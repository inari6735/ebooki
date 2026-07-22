<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Category;
use App\Ebook\Domain\CategoryRepository;
use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\EbookStatus;
use App\Ebook\Domain\Pricing\Pricing;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Public catalogue lists only PUBLISHED eBooks, supports category filtering.
 */
final class CatalogTest extends WebTestCase
{
    public function testListsPublishedButNotDrafts(): void
    {
        $client = self::createClient();
        $this->makeEbook('Widoczny tytuł', 'widoczny', EbookStatus::PUBLISHED);
        $this->makeEbook('Ukryty szkic', 'szkic', EbookStatus::DRAFT);

        $client->request('GET', 'https://localhost/ebooki');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Widoczny tytuł');
        self::assertSelectorTextContains('body', 'Odkrywaj');
        self::assertStringNotContainsString('Ukryty szkic', (string) $client->getResponse()->getContent());
    }

    public function testCategoryFilter(): void
    {
        $client = self::createClient();
        $category = self::getContainer()->get(CategoryRepository::class)->all()[0];
        $this->makeEbook('W kategorii', 'w-kategorii', EbookStatus::PUBLISHED, $category);
        $this->makeEbook('Bez kategorii', 'bez-kategorii', EbookStatus::PUBLISHED);

        $client->request('GET', 'https://localhost/ebooki?kategoria=' . $category->getSlug());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('W kategorii', $html);
        self::assertStringNotContainsString('Bez kategorii', $html);
    }

    public function testInvalidSortFallsBack(): void
    {
        $client = self::createClient();
        $this->makeEbook('Tytuł', 'tytul', EbookStatus::PUBLISHED);

        $client->request('GET', 'https://localhost/ebooki?sort=;DROP');

        self::assertResponseIsSuccessful();
    }

    private ?Uuid $owner = null;

    private function owner(): Uuid
    {
        if (null === $this->owner) {
            $this->owner = Uuid::v7();
            self::getContainer()->get(CommandBus::class)->dispatch(
                new RegisterUser($this->owner->toRfc4122(), 'owner-' . $this->owner->toRfc4122() . '@example.com', 'password123'),
            );
        }

        return $this->owner;
    }

    private function makeEbook(string $title, string $slug, EbookStatus $status, ?Category $category = null): void
    {
        $ebook = new Ebook(Uuid::v7(), $this->owner(), $title, $slug, 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        if (null !== $category) {
            $ebook->assignCategory($category);
        }
        if (EbookStatus::PUBLISHED === $status) {
            $ebook->publish(new \DateTimeImmutable());
        }
        self::getContainer()->get(EbookRepository::class)->save($ebook);
    }
}
