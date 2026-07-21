<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The homepage carries a "how easy it is to publish" section under the hero:
 * heading, the three native step cards, and a CTA into the publish wizard.
 */
final class HomePublishFlowTest extends WebTestCase
{
    public function testHomepageShowsPublishFlowSection(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://localhost/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Wystaw eBooka w 3 prostych krokach');
        self::assertSelectorTextContains('body', 'Wypełnij formularz');
        self::assertSelectorTextContains('body', 'Opublikuj eBook');
        self::assertSelectorExists('a[href*="wystaw-ebook"]');
    }
}
