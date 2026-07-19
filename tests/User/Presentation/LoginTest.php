<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class LoginTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'login@example.com', 'password123'),
        );
    }

    private function submitLogin(string $email, string $password): void
    {
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function testLoginCsrfFieldLoadsTheCsrfProtectionController(): void
    {
        // Without data-controller="csrf-protection", the lazy Stimulus controller
        // performing the stateless double-submit never loads on full page loads of
        // /login, making CSRF validation depend on which pages were visited earlier
        // in the same Turbo session (anti-downgrade 403s after a full reload).
        $this->client->request('GET', 'https://localhost/login');

        self::assertSelectorExists('form input[name="_csrf_token"][data-controller="csrf-protection"]');
    }

    public function testSuccessfulLoginSetsBothCookiesAndRedirects(): void
    {
        $this->submitLogin('login@example.com', 'password123');

        self::assertResponseRedirects('/');

        $cookies = $this->client->getResponse()->headers->getCookies();
        $names = array_map(fn ($c) => $c->getName(), $cookies);
        self::assertContains('AUTH_TOKEN', $names);
        self::assertContains('REFRESH_TOKEN', $names);

        foreach ($cookies as $cookie) {
            self::assertTrue($cookie->isHttpOnly());
            self::assertTrue($cookie->isSecure());
            self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        }
    }

    public function testFailedLoginShowsGenericError(): void
    {
        $this->submitLogin('login@example.com', 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid email or password.');

        $names = array_map(
            fn ($c) => $c->getName(),
            $this->client->getResponse()->headers->getCookies(),
        );
        self::assertNotContains('AUTH_TOKEN', $names);
    }

    public function testUnknownEmailShowsSameGenericError(): void
    {
        $this->submitLogin('ghost@example.com', 'password123');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid email or password.');
    }

    public function testMaliciousTargetPathFallsBackToHome(): void
    {
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => 'login@example.com',
            'password' => 'password123',
            '_target_path' => '/\\evil.com',
        ]);

        self::assertResponseRedirects('/');
    }

    public function testRelativeTargetPathIsHonoured(): void
    {
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => 'login@example.com',
            'password' => 'password123',
            '_target_path' => '/register',
        ]);

        self::assertResponseRedirects('/register');
    }
}
