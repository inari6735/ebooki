<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class SilentRefreshTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'silent@example.com', 'password123'),
        );
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => 'silent@example.com',
            'password' => 'password123',
        ]);
    }

    private function refreshCookieValue(): string
    {
        return $this->client->getCookieJar()->get('REFRESH_TOKEN', '/', 'localhost')->getValue();
    }

    public function testMissingAccessTokenIsSilentlyReissued(): void
    {
        $oldRefresh = $this->refreshCookieValue();
        $this->client->getCookieJar()->expire('AUTH_TOKEN', '/', 'localhost');

        $this->client->request('GET', 'https://localhost/login');

        self::assertResponseIsSuccessful();
        $names = array_map(
            fn ($c) => $c->getName(),
            $this->client->getResponse()->headers->getCookies(),
        );
        self::assertContains('AUTH_TOKEN', $names, 'a fresh access token must be attached');
        self::assertContains('REFRESH_TOKEN', $names, 'the refresh token must rotate');
        self::assertNotSame($oldRefresh, $this->refreshCookieValue());
    }

    public function testReusedRefreshTokenRevokesEverything(): void
    {
        $stolen = $this->refreshCookieValue();

        // Legitimate rotation consumes $stolen.
        $this->client->getCookieJar()->expire('AUTH_TOKEN', '/', 'localhost');
        $this->client->request('GET', 'https://localhost/login');
        $current = $this->refreshCookieValue();
        self::assertNotSame($stolen, $current);

        // Attacker replays the consumed token.
        $this->client->getCookieJar()->expire('AUTH_TOKEN', '/', 'localhost');
        $this->client->getCookieJar()->expire('REFRESH_TOKEN', '/', 'localhost');
        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie('REFRESH_TOKEN', $stolen, null, '/', 'localhost', true, true),
        );
        $this->client->request('GET', 'https://localhost/login');

        $manager = self::getContainer()->get(RefreshTokenManagerInterface::class);
        self::assertNull($manager->get($stolen), 'reused token must be gone');
        self::assertNull($manager->get($current), 'ALL tokens of the user must be revoked');
    }
}
