<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\EbookStatus;
use App\Ebook\Domain\Pricing\Pricing;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\User\Application\Command\RegisterUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * "Wystawione": the owner can hide/show and delete their eBooks; another user's
 * eBook is not reachable.
 */
final class ListedEbooksTest extends WebTestCase
{
    public function testOwnerCanHideThenDeleteTheirEbook(): void
    {
        $client = self::createClient();
        $userId = $this->register('author@example.com');
        $this->login($client, 'author@example.com');
        $ebook = $this->publishEbook($userId, 'Mój poradnik');

        // The management page lists it as published.
        $crawler = $client->request('GET', 'https://localhost/panel/wystawione');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Wystawione eBooki');
        self::assertSelectorTextContains('body', 'Mój poradnik');
        self::assertSelectorTextContains('body', 'Opublikowany');

        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $id = $ebook->getId()->toRfc4122();

        // Hide it → status becomes UNPUBLISHED.
        $client->request('POST', 'https://localhost/panel/wystawione/'.$id.'/widocznosc', ['_token' => $token]);
        self::assertResponseRedirects('/panel/wystawione');
        self::assertSame(EbookStatus::UNPUBLISHED, $this->reload($id)->getStatus());

        // Delete it → gone.
        $client->request('POST', 'https://localhost/panel/wystawione/'.$id.'/usun', ['_token' => $token]);
        self::assertResponseRedirects('/panel/wystawione');
        self::assertNull($this->reload($id));
    }

    public function testAnotherUsersEbookCannotBeManaged(): void
    {
        $client = self::createClient();
        $this->register('me@example.com');
        $this->login($client, 'me@example.com');
        $foreign = $this->publishEbook($this->register('other@example.com'), 'Cudzy eBook');

        $client->request('POST', 'https://localhost/panel/wystawione/'.$foreign->getId()->toRfc4122().'/usun', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->reload($foreign->getId()->toRfc4122()));
    }

    private function register(string $email): Uuid
    {
        $id = Uuid::v7();
        self::getContainer()->get(CommandBus::class)->dispatch(new RegisterUser($id->toRfc4122(), $email, 'password123'));

        return $id;
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $client->request('GET', 'https://localhost/login');
        $client->submitForm('Zaloguj się', ['email' => $email, 'password' => 'password123']);
    }

    private function publishEbook(Uuid $ownerId, string $title): Ebook
    {
        $ebook = new Ebook(Uuid::v7(), $ownerId, $title, Uuid::v7()->toRfc4122(), 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        $ebook->publish(new \DateTimeImmutable());
        self::getContainer()->get(EbookRepository::class)->save($ebook);

        return $ebook;
    }

    private function reload(string $id): ?Ebook
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(EbookRepository::class)->get(Uuid::fromString($id));
    }
}
