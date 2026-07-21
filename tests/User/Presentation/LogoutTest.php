<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use App\User\Infrastructure\Security\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class LogoutTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'bye@example.com', 'password123'),
        );
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Zaloguj się', [
            'email' => 'bye@example.com',
            'password' => 'password123',
        ]);
        $this->client->followRedirect();
    }

    public function testNavShowsUserEmailWhenLoggedIn(): void
    {
        // The email lives in the header's action area, not inside a <nav>.
        self::assertSelectorTextContains('header', 'bye@example.com');
    }

    public function testLogoutCsrfFieldLoadsTheCsrfProtectionController(): void
    {
        // Without data-controller="csrf-protection", the lazy Stimulus controller
        // that performs the stateless double-submit never loads on full page loads,
        // and SameOriginCsrfTokenManager's anti-downgrade check 403s the logout
        // once a previous request in the session validated via double-submit.
        self::assertSelectorExists('nav form input[name="_csrf_token"][data-controller="csrf-protection"]');
    }

    public function testLogoutClearsCookiesAndRevokesRefreshTokens(): void
    {
        $this->client->submitForm('Wyloguj');

        self::assertResponseRedirects('/login');

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if (\in_array($cookie->getName(), ['AUTH_TOKEN', 'REFRESH_TOKEN'], true)) {
                self::assertLessThan(time(), $cookie->getExpiresTime(), $cookie->getName() . ' must be expired');
            }
        }

        $count = self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery(sprintf('SELECT COUNT(rt) FROM %s rt WHERE rt.username = :u', RefreshToken::class))
            ->setParameter('u', 'bye@example.com')
            ->getSingleScalarResult();
        self::assertSame(0, (int) $count);

        // Back on a normal page, the header now offers to log in again.
        $this->client->request('GET', 'https://localhost/');
        self::assertSelectorTextContains('header', 'Zaloguj się');
    }

    public function testLogoutWithoutCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', 'https://localhost/logout');

        self::assertResponseStatusCodeSame(403);

        $count = self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery(sprintf('SELECT COUNT(rt) FROM %s rt WHERE rt.username = :u', RefreshToken::class))
            ->setParameter('u', 'bye@example.com')
            ->getSingleScalarResult();
        self::assertGreaterThan(0, (int) $count, 'refresh tokens must survive a rejected logout');
    }
}
