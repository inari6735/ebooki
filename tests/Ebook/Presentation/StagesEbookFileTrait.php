<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stages an eBook file through the chunked upload flow (init → chunk → finalize)
 * exactly as the browser does. Small test payloads fit in a single chunk. The
 * helper stops at the first non-success step, leaving that HTTP response on the
 * client for assertions (so a rejection at init or finalize is observable).
 */
trait StagesEbookFileTrait
{
    private function stageEbookFile(KernelBrowser $client, string $token, string $name, string $content): void
    {
        $size = \strlen($content);

        $client->request('POST', 'https://localhost/wystaw-ebook/plik/init', ['_token' => $token, 'name' => $name, 'size' => $size]);
        if (201 !== $client->getResponse()->getStatusCode()) {
            return;
        }
        $uploadId = json_decode((string) $client->getResponse()->getContent(), true)['uploadId'];

        $client->request('POST', 'https://localhost/wystaw-ebook/plik/chunk', ['_token' => $token, 'uploadId' => $uploadId, 'index' => 0], ['chunk' => $this->chunkUpload($content)]);
        if (204 !== $client->getResponse()->getStatusCode()) {
            return;
        }

        $client->request('POST', 'https://localhost/wystaw-ebook/plik/finalize', ['_token' => $token, 'uploadId' => $uploadId, 'total' => 1, 'name' => $name, 'size' => $size, 'mime' => 'application/octet-stream']);
    }

    private function chunkUpload(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'chunk.part', 'application/octet-stream', null, true);
    }
}
