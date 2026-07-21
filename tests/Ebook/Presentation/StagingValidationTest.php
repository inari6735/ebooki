<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Server-side enforcement of the upload rules (the frontend applies the same ones
 * for instant feedback): one file per format, no duplicate file, unsupported
 * format. Files go through the chunked flow (init → chunk → finalize).
 */
final class StagingValidationTest extends WebTestCase
{
    use StagesEbookFileTrait;

    private KernelBrowser $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $crawler = $this->client->request('GET', 'https://localhost/wystaw-ebook/1');
        $this->token = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');
    }

    public function testRejectsSecondFileOfSameFormat(): void
    {
        $this->stageEbookFile($this->client, $this->token, 'a.pdf', 'CONTENT-A');
        self::assertResponseStatusCodeSame(201);

        // Rejected up front at init — same format, before any bytes are re-sent.
        $this->stageEbookFile($this->client, $this->token, 'b.pdf', 'CONTENT-B');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('formacie PDF', $this->error());
    }

    public function testRejectsTheSameFileTwice(): void
    {
        $this->stageEbookFile($this->client, $this->token, 'a.pdf', 'IDENTICAL');
        self::assertResponseStatusCodeSame(201);

        // Same bytes, different extension → not a format clash, but a duplicate
        // file the server only detects (by checksum) once assembled at finalize.
        $this->stageEbookFile($this->client, $this->token, 'a.epub', 'IDENTICAL');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ten plik został już dodany', $this->error());
    }

    public function testRejectsUnsupportedFormat(): void
    {
        $this->stageEbookFile($this->client, $this->token, 'malware.exe', 'X');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Nieobsługiwany', $this->error());
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
