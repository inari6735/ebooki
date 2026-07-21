<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\Storage\FileStorage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * The chunked upload path itself: parts are reassembled in order into one file,
 * and a transfer that is incomplete or the wrong size NEVER yields a usable Media
 * — finalize fails cleanly instead. This is the guarantee that a file can't be
 * "half uploaded" and treated as done.
 */
final class ChunkedUploadTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $crawler = $this->client->request('GET', 'https://localhost/wystaw-ebook/1');
        $this->token = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');
    }

    public function testAssemblesMultipleChunksInOrder(): void
    {
        $part0 = str_repeat('A', 1000);
        $part1 = str_repeat('B', 400);
        $content = $part0.$part1;

        $uploadId = $this->init('big.pdf', \strlen($content));
        self::assertResponseStatusCodeSame(201);

        $this->chunk($uploadId, 0, $part0);
        self::assertResponseStatusCodeSame(204);
        $this->chunk($uploadId, 1, $part1);
        self::assertResponseStatusCodeSame(204);

        $mediaId = $this->finalize($uploadId, 2, 'big.pdf', \strlen($content));
        self::assertResponseStatusCodeSame(201);

        // The stored blob is byte-for-byte the concatenation, in index order.
        $media = self::getContainer()->get(MediaRepository::class)->get(Uuid::fromString($mediaId));
        self::assertNotNull($media);
        self::assertSame(\strlen($content), $media->getSize());
        $stream = self::getContainer()->get(FileStorage::class)->readStream($media->getPath());
        self::assertSame($content, stream_get_contents($stream));
    }

    public function testIncompleteUploadIsRejectedAndStagesNothing(): void
    {
        $uploadId = $this->init('book.pdf', 2000);
        self::assertResponseStatusCodeSame(201);

        // Only chunk 0 arrives, but finalize declares two chunks.
        $this->chunk($uploadId, 0, str_repeat('A', 1000));
        $mediaId = $this->finalize($uploadId, 2, 'book.pdf', 2000);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($mediaId);
        self::assertStringContainsString('brakuje', $this->error());
    }

    public function testSizeMismatchIsRejected(): void
    {
        $uploadId = $this->init('book.pdf', 1500);
        self::assertResponseStatusCodeSame(201);

        // A single chunk of 1000 bytes, but the client claims the file is 1500.
        $this->chunk($uploadId, 0, str_repeat('A', 1000));
        $mediaId = $this->finalize($uploadId, 1, 'book.pdf', 1500);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($mediaId);
        self::assertStringContainsString('niekompletnie', $this->error());
    }

    private function init(string $name, int $size): string
    {
        $this->client->request('POST', 'https://localhost/wystaw-ebook/plik/init', ['_token' => $this->token, 'name' => $name, 'size' => $size]);

        return json_decode((string) $this->client->getResponse()->getContent(), true)['uploadId'] ?? '';
    }

    private function chunk(string $uploadId, int $index, string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $content);
        $this->client->request(
            'POST',
            'https://localhost/wystaw-ebook/plik/chunk',
            ['_token' => $this->token, 'uploadId' => $uploadId, 'index' => $index],
            ['chunk' => new UploadedFile($path, 'chunk.part', 'application/octet-stream', null, true)],
        );
    }

    private function finalize(string $uploadId, int $total, string $name, int $size): ?string
    {
        $this->client->request('POST', 'https://localhost/wystaw-ebook/plik/finalize', [
            '_token' => $this->token, 'uploadId' => $uploadId, 'total' => $total, 'name' => $name, 'size' => $size, 'mime' => 'application/pdf',
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true)['mediaId'] ?? null;
    }

    private function error(): string
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');
        parent::tearDown();
    }
}
