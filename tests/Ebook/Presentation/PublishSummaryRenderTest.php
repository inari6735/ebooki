<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Smoke test for the redesigned step-4 "Publikacja" view: with a staged file it
 * renders, shows the readiness summary and the relocated draft action, and no
 * longer carries a bottom-bar "Podgląd" button.
 */
final class PublishSummaryRenderTest extends WebTestCase
{
    use StagesEbookFileTrait;

    public function testStep4RendersSummaryWithRelocatedActions(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', 'https://localhost/wystaw-ebook/1');
        $token = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');
        $this->stageEbookFile($client, $token, 'book.pdf', "%PDF-1.4 test\n");

        $client->request('GET', 'https://localhost/wystaw-ebook/4');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Podsumowanie publikacji', $html);
        self::assertStringContainsString('Gotowe do publikacji!', $html);
        // Redesigned summary: grouped spec sheet + the detail-page preview action.
        self::assertStringContainsString('O eBooku', $html);
        self::assertStringContainsString('Cena i sprzedaż', $html);
        self::assertStringContainsString('Zobacz podgląd strony eBooka', $html);
        // Draft action lives in the side panel (out of the bottom bar).
        self::assertSelectorExists('a[form="draft-form"], button[form="draft-form"]');
        // The publish action bar keeps only Wstecz + Opublikuj (no "Podgląd").
        self::assertStringNotContainsString('>Podgląd<', $html);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');
        parent::tearDown();
    }
}
