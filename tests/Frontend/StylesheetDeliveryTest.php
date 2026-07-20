<?php declare(strict_types=1);

namespace App\Tests\Frontend;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StylesheetDeliveryTest extends WebTestCase
{
    public function testLoginPageDeliversACompiledStylesheet(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://localhost/login');

        self::assertResponseIsSuccessful();
        // AssetMapper emits <link rel="stylesheet"> for app.css (imported by app.js);
        // tailwind-bundle serves the COMPILED Tailwind for that asset. If this link
        // disappears, the whole app ships unstyled while markup tests stay green.
        self::assertSelectorExists('link[rel="stylesheet"]');
    }
}
