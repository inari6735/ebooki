<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure\Thumbnail;

use App\Ebook\Domain\Thumbnail\GeneratedThumbnail;
use App\Ebook\Domain\Thumbnail\ThumbnailGenerator;
use App\Ebook\Domain\Thumbnail\ThumbnailSpec;
use App\Media\Grpc\GenerateThumbnailsRequest;
use App\Media\Grpc\MediaServiceClient;
use App\Media\Grpc\ThumbnailSpec as PbThumbnailSpec;
use Grpc\ChannelCredentials;

/**
 * ThumbnailGenerator backed by the Go media service over gRPC. The heavy image
 * work happens next to the bytes in Go; this adapter only translates the request
 * and reports back exactly what was written. Requires ext-grpc at runtime.
 */
final class GrpcThumbnailGenerator implements ThumbnailGenerator
{
    private readonly MediaServiceClient $client;

    public function __construct(string $endpoint)
    {
        $this->client = new MediaServiceClient($endpoint, [
            'credentials' => ChannelCredentials::createInsecure(),
        ]);
    }

    public function generate(string $sourceKey, array $specs, string $format, int $quality): array
    {
        $request = (new GenerateThumbnailsRequest())
            ->setSourceKey($sourceKey)
            ->setFormat($format)
            ->setQuality($quality)
            ->setSpecs(array_map(
                static fn (ThumbnailSpec $s): PbThumbnailSpec => (new PbThumbnailSpec())
                    ->setAspectW($s->aspectW)
                    ->setAspectH($s->aspectH)
                    ->setWidth($s->width),
                $specs,
            ));

        [$response, $status] = $this->client->GenerateThumbnails($request)->wait();
        if (\Grpc\STATUS_OK !== $status->code) {
            throw new \RuntimeException(\sprintf(
                'media gRPC GenerateThumbnails(%s) failed: [%d] %s',
                $sourceKey,
                $status->code,
                $status->details,
            ));
        }

        $out = [];
        foreach ($response->getThumbnails() as $t) {
            $out[] = new GeneratedThumbnail($t->getKey(), $t->getWidth(), $t->getHeight(), (int) $t->getSizeBytes());
        }

        return $out;
    }
}
