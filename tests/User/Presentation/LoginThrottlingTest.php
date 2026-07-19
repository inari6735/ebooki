<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class LoginThrottlingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'throttle@example.com', 'password123'),
        );
    }

    public function testSixthAttemptIsThrottledEvenWithCorrectPassword(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', 'https://localhost/login');
            $this->client->submitForm('Log in', [
                'email' => 'throttle@example.com',
                'password' => 'wrong-' . $i,
            ]);
        }

        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => 'throttle@example.com',
            'password' => 'password123',
        ]);

        self::assertResponseRedirects('/login');
        $names = array_map(
            fn ($c) => $c->getName(),
            $this->client->getResponse()->headers->getCookies(),
        );
        self::assertNotContains('AUTH_TOKEN', $names, 'throttled login must not issue tokens');
    }
}
