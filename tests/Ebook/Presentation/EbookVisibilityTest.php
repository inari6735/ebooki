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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Only PUBLISHED eBooks are reachable at their public /ebook/{slug} link. A draft is
 * a 404 for the public, but its owner can still preview it; unknown slugs are 404.
 */
final class EbookVisibilityTest extends WebTestCase
{
    public function testPublishedEbookIsPublic(): void
    {
        $client = self::createClient();
        $this->makeEbook('opublikowany', EbookStatus::PUBLISHED, $this->register('a@example.com'));

        $client->request('GET', 'https://localhost/ebook/opublikowany');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Dostępny tytuł');
    }

    public function testDraftIsNotPublic(): void
    {
        $client = self::createClient();
        $this->makeEbook('szkic', EbookStatus::DRAFT, $this->register('a@example.com'));

        $client->request('GET', 'https://localhost/ebook/szkic');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerCanPreviewOwnDraft(): void
    {
        $client = self::createClient();
        $ownerId = $this->register('owner@example.com');
        $this->login($client, 'owner@example.com');
        $this->makeEbook('szkic', EbookStatus::DRAFT, $ownerId);

        $client->request('GET', 'https://localhost/ebook/szkic');

        self::assertResponseIsSuccessful();
    }

    public function testUnknownSlugIs404(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://localhost/ebook/nie-istnieje');

        self::assertResponseStatusCodeSame(404);
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

    private function makeEbook(string $slug, EbookStatus $status, Uuid $ownerId): void
    {
        $ebook = new Ebook(Uuid::v7(), $ownerId, 'Dostępny tytuł', $slug, 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        if (EbookStatus::PUBLISHED === $status) {
            $ebook->publish(new \DateTimeImmutable());
        }
        self::getContainer()->get(EbookRepository::class)->save($ebook);
    }
}
