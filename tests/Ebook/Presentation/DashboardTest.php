<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The author panel at /panel: gated behind login, and (for a fresh account) shows
 * the greeting + the empty-state prompt to publish a first eBook.
 */
final class DashboardTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://localhost/panel');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testPulpitShowsMenuStatsAndSupport(): void
    {
        $client = self::createClient();
        $this->login($client, 'author@example.com');

        $client->request('GET', 'https://localhost/panel');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pulpit');
        // Panel menu.
        self::assertSelectorTextContains('body', 'Wystawione');
        self::assertSelectorTextContains('body', 'Zakupione');
        self::assertSelectorTextContains('body', 'Ustawienia');
        // General stats + shortcuts + easy support contact.
        self::assertSelectorTextContains('body', 'Szybkie akcje');
        self::assertSelectorExists('a[href^="mailto:"]');
    }

    private function login(KernelBrowser $client, string $email): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), $email, 'password123'),
        );
        $client->request('GET', 'https://localhost/login');
        $client->submitForm('Zaloguj się', ['email' => $email, 'password' => 'password123']);
    }
}
