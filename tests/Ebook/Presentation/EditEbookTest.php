<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Category;
use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookFile;
use App\Ebook\Domain\EbookFileFormat;
use App\Ebook\Domain\EbookFileRole;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Media;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaStatus;
use App\Ebook\Domain\MediaVisibility;
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
 * Editing reuses the wizard's fields + validation: a prefilled form saves changed
 * details/pricing, and the same rules (e.g. required title) block a bad save.
 */
final class EditEbookTest extends WebTestCase
{
    public function testOwnerEditsDetailsAndPricing(): void
    {
        $client = self::createClient();
        $ebook = $this->seed($client);
        $id = $ebook->getId()->toRfc4122();

        // The form is prefilled with the current values.
        $crawler = $client->request('GET', 'https://localhost/panel/ebook/'.$id.'/edytuj');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Edytuj eBook');
        self::assertInputValueSame('ebook_edit[title]', 'Stary tytuł');

        // Change the title + price → persisted.
        $client->request('POST', 'https://localhost/panel/ebook/'.$id.'/edytuj', [
            'ebook_edit' => [
                'title' => 'Nowy tytuł', 'author' => 'Autor', 'category' => 'kategoria-testowa', 'language' => 'pl',
                'shortDescription' => 'Krótki opis', 'description' => 'Dłuższy opis.', 'price' => '55',
                '_token' => $this->token($crawler),
            ],
        ]);

        self::assertResponseRedirects('/panel/wystawione');
        $fresh = $this->reload($id);
        self::assertSame('Nowy tytuł', $fresh->getTitle());
        self::assertSame(5500, $fresh->pricing()->baseAmountMinor());
    }

    public function testInvalidEditIsRejected(): void
    {
        $client = self::createClient();
        $ebook = $this->seed($client);
        $id = $ebook->getId()->toRfc4122();

        $crawler = $client->request('GET', 'https://localhost/panel/ebook/'.$id.'/edytuj');

        // Empty title violates the same rule as on the add form.
        $client->request('POST', 'https://localhost/panel/ebook/'.$id.'/edytuj', [
            'ebook_edit' => [
                'title' => '', 'author' => 'Autor', 'category' => 'kategoria-testowa', 'language' => 'pl',
                'shortDescription' => 'Krótki opis', 'description' => 'Dłuższy opis.', 'price' => '55',
                '_token' => $this->token($crawler),
            ],
        ]);

        self::assertResponseStatusCodeSame(422); // re-rendered with errors (Turbo-friendly)
        self::assertSame('Stary tytuł', $this->reload($id)->getTitle());
    }

    private function seed(KernelBrowser $client): Ebook
    {
        $userId = Uuid::v7();
        self::getContainer()->get(CommandBus::class)->dispatch(new RegisterUser($userId->toRfc4122(), 'author@example.com', 'password123'));
        $client->request('GET', 'https://localhost/login');
        $client->submitForm('Zaloguj się', ['email' => 'author@example.com', 'password' => 'password123']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Category(Uuid::v7(), 'Kategoria testowa', 'kategoria-testowa'));
        $em->flush();

        $ebook = new Ebook(Uuid::v7(), $userId, 'Stary tytuł', 'stary-tytul', 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        $ebook->publish(new \DateTimeImmutable());

        // A real eBook always has at least one file (the edit gate requires it).
        $media = new Media(Uuid::v7(), 'local', 'ebooks/'.$ebook->getId()->toRfc4122().'/book.pdf', 'book.pdf', 'application/pdf', 'pdf', 1000, MediaVisibility::PRIVATE, $userId, checksum: 'abc123', status: MediaStatus::READY);
        self::getContainer()->get(MediaRepository::class)->save($media);
        $ebook->addFile(new EbookFile(Uuid::v7(), $ebook, $media, EbookFileFormat::PDF, EbookFileRole::FULL, true));
        self::getContainer()->get(EbookRepository::class)->save($ebook);

        return $ebook;
    }

    private function token(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return $crawler->filter('input[name="ebook_edit[_token]"]')->attr('value');
    }

    private function reload(string $id): Ebook
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(EbookRepository::class)->get(Uuid::fromString($id));
    }
}
