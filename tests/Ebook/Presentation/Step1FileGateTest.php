<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Step 1 may only be left once an eBook file has uploaded COMPLETELY: the file's
 * session reference is written by finalize alone, and "Dalej" gates on it — so a
 * file that is merely selected/started (init + chunk, no finalize) does not let
 * the author advance. The frontend blocks the click too, but this proves the
 * authoritative server guarantee.
 */
final class Step1FileGateTest extends WebTestCase
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

    public function testCannotAdvanceWithNoFile(): void
    {
        $this->client->request('POST', 'https://localhost/wystaw-ebook/1');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/wystaw-ebook', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCannotAdvanceWhileUploadIsUnfinished(): void
    {
        // Bytes have started arriving (init + one chunk) but finalize hasn't run,
        // so there is no staged reference yet.
        $this->client->request('POST', 'https://localhost/wystaw-ebook/plik/init', ['_token' => $this->token, 'name' => 'book.pdf', 'size' => 4000]);
        $uploadId = json_decode((string) $this->client->getResponse()->getContent(), true)['uploadId'];
        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, str_repeat('A', 1000));
        $this->client->request('POST', 'https://localhost/wystaw-ebook/plik/chunk', ['_token' => $this->token, 'uploadId' => $uploadId, 'index' => 0], ['chunk' => new UploadedFile($path, 'chunk.part', 'application/octet-stream', null, true)]);

        $this->client->request('POST', 'https://localhost/wystaw-ebook/1');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/wystaw-ebook', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAdvancesOnceTheFileIsFullyUploaded(): void
    {
        $this->stageEbookFile($this->client, $this->token, 'book.pdf', "%PDF-1.4 test\n");
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', 'https://localhost/wystaw-ebook/1');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/wystaw-ebook/2', (string) $this->client->getResponse()->headers->get('Location'));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');
        parent::tearDown();
    }
}
