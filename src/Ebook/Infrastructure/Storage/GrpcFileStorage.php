<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure\Storage;

use App\Ebook\Domain\Storage\FileStorage;
use App\Media\Grpc\DeleteDirectoryRequest;
use App\Media\Grpc\DeleteRequest;
use App\Media\Grpc\DirectoriesRequest;
use App\Media\Grpc\MediaServiceClient;
use App\Media\Grpc\MoveRequest;
use App\Media\Grpc\ReadRequest;
use App\Media\Grpc\StatRequest;
use App\Media\Grpc\StoreRequest;
use Grpc\ChannelCredentials;

/**
 * FileStorage adapter that delegates every file operation to the Go media
 * service over gRPC. It is a drop-in alternative to FlysystemFileStorage: the
 * domain still depends only on the FileStorage port and never learns that the
 * bytes now live behind a separate service.
 *
 * Only the CONTROL plane runs through here. Browser-facing downloads of covers
 * and purchased files are meant to go through short-lived signed HTTP URLs served
 * directly by the Go service (a later addition), so PHP-FPM workers never sit
 * blocked streaming large files.
 *
 * Requires the `grpc` PHP extension and a protobuf runtime (ext-protobuf or the
 * google/protobuf package) at runtime — see docs; generation is already done.
 */
final class GrpcFileStorage implements FileStorage
{
    /** Upload/download chunk size (256 KiB) — bytes are streamed, never buffered whole. */
    private const int CHUNK = 262144;

    private readonly MediaServiceClient $client;

    /**
     * @param string $endpoint host:port of the Go service on the private network,
     *                         e.g. "media.internal:8090"
     */
    public function __construct(string $endpoint)
    {
        // Insecure channel: the link is private (internal network on Upsun), and
        // TLS termination — if any — happens at the platform edge.
        $this->client = new MediaServiceClient($endpoint, [
            'credentials' => ChannelCredentials::createInsecure(),
        ]);
    }

    /** @param resource $contents */
    public function writeStream(string $path, $contents): void
    {
        $call = $this->client->Store();
        // First message carries the key; the server reads it from the first Recv.
        $call->write((new StoreRequest())->setKey($path));
        while (!feof($contents)) {
            $chunk = fread($contents, self::CHUNK);
            if (false === $chunk || '' === $chunk) {
                continue;
            }
            $call->write((new StoreRequest())->setChunk($chunk));
        }
        [, $status] = $call->wait();
        $this->ensureOk($status, 'writeStream', $path);
    }

    /** @return resource */
    public function readStream(string $path)
    {
        $call = $this->client->Read((new ReadRequest())->setKey($path));

        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new \RuntimeException('media: cannot allocate a read buffer');
        }
        foreach ($call->responses() as $response) {
            fwrite($stream, $response->getChunk());
        }
        $this->ensureOk($call->getStatus(), 'readStream', $path);
        rewind($stream);

        return $stream;
    }

    public function move(string $source, string $destination): void
    {
        [, $status] = $this->client
            ->Move((new MoveRequest())->setSource($source)->setDestination($destination))
            ->wait();
        $this->ensureOk($status, 'move', $source);
    }

    public function delete(string $path): void
    {
        [, $status] = $this->client->Delete((new DeleteRequest())->setKey($path))->wait();
        $this->ensureOk($status, 'delete', $path);
    }

    public function fileExists(string $path): bool
    {
        [, $status] = $this->client->Stat((new StatRequest())->setKey($path))->wait();

        return match ($status->code) {
            \Grpc\STATUS_OK => true,
            \Grpc\STATUS_NOT_FOUND => false,
            default => throw $this->error($status, 'fileExists', $path),
        };
    }

    public function fileSize(string $path): int
    {
        [$response, $status] = $this->client->Stat((new StatRequest())->setKey($path))->wait();
        $this->ensureOk($status, 'fileSize', $path);

        return (int) $response->getSize();
    }

    public function deleteDirectory(string $path): void
    {
        [, $status] = $this->client
            ->DeleteDirectory((new DeleteDirectoryRequest())->setPrefix($path))
            ->wait();
        $this->ensureOk($status, 'deleteDirectory', $path);
    }

    /** @return list<string> */
    public function directories(string $path): array
    {
        [$response, $status] = $this->client
            ->Directories((new DirectoriesRequest())->setPrefix($path))
            ->wait();
        $this->ensureOk($status, 'directories', $path);

        $names = [];
        foreach ($response->getNames() as $name) {
            $names[] = $name;
        }

        return $names;
    }

    private function ensureOk(object $status, string $op, string $path): void
    {
        if (\Grpc\STATUS_OK !== $status->code) {
            throw $this->error($status, $op, $path);
        }
    }

    private function error(object $status, string $op, string $path): \RuntimeException
    {
        return new \RuntimeException(\sprintf(
            'media gRPC %s(%s) failed: [%d] %s',
            $op,
            $path,
            $status->code,
            $status->details,
        ));
    }
}
