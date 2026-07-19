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
        $this->client->submitForm('Log in', [
            'email' => 'bye@example.com',
            'password' => 'password123',
        ]);
        $this->client->followRedirect();
    }

    public function testNavShowsUserEmailWhenLoggedIn(): void
    {
        self::assertSelectorTextContains('nav', 'bye@example.com');
    }

    public function testLogoutClearsCookiesAndRevokesRefreshTokens(): void
    {
        $this->client->submitForm('Log out');

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

        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Log in');
    }
}
