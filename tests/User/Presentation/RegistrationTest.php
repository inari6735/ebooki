<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\User\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testRegistrationFormRenders(): void
    {
        $this->client->request('GET', 'https://localhost/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="registration[email]"]');
    }

    public function testSuccessfulRegistrationRedirectsToLogin(): void
    {
        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'newreader@example.com',
            'registration[plainPassword][first]' => 'password123',
            'registration[plainPassword][second]' => 'password123',
        ]);

        self::assertResponseRedirects('/login');

        $repository = self::getContainer()->get(UserRepository::class);
        $user = $repository->byEmail('newreader@example.com');
        self::assertNotNull($user);
        self::assertNotSame('', $user->getPassword());
        self::assertStringStartsNotWith('password123', $user->getPassword());
    }

    public function testDuplicateEmailShowsFormError(): void
    {
        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'dupe@example.com',
            'registration[plainPassword][first]' => 'password123',
            'registration[plainPassword][second]' => 'password123',
        ]);

        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'dupe@example.com',
            'registration[plainPassword][first]' => 'password123',
            'registration[plainPassword][second]' => 'password123',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'already registered');
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'short@example.com',
            'registration[plainPassword][first]' => 'short',
            'registration[plainPassword][second]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            self::getContainer()->get(UserRepository::class)->byEmail('short@example.com'),
        );
    }
}
