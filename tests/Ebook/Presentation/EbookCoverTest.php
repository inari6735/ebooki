<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Media;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaVisibility;
use App\Ebook\Domain\Pricing\Pricing;
use App\Ebook\Domain\Storage\FileStorage;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * eBook covers are streamed from storage for published eBooks and shown on the
 * catalogue card / detail page.
 */
final class EbookCoverTest extends WebTestCase
{
    private const string IMG = 'PNG-fake-cover-bytes-\x89';

    public function testServesPublishedCover(): void
    {
        $client = self::createClient();
        $id = $this->makeEbookWithCover('okladka-test');

        $client->request('GET', 'https://localhost/ebook/okladka/' . $id);

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame((string) \strlen(self::IMG), $client->getResponse()->headers->get('Content-Length'));
    }

    public function testCatalogCardRendersCover(): void
    {
        $client = self::createClient();
        $id = $this->makeEbookWithCover('okladka-na-liscie');

        $client->request('GET', 'https://localhost/ebooki');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/ebook/okladka/' . $id, (string) $client->getResponse()->getContent());
    }

    public function testInvalidIdIs404(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://localhost/ebook/okladka/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownEbookIs404(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://localhost/ebook/okladka/' . Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    private function makeEbookWithCover(string $slug): string
    {
        $ownerId = Uuid::v7();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser($ownerId->toRfc4122(), 'owner-' . $ownerId->toRfc4122() . '@example.com', 'password123'),
        );

        $mediaId = Uuid::v7();
        $path = 'covers/' . $mediaId->toRfc4122() . '.png';
        $storage = self::getContainer()->get(FileStorage::class);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, self::IMG);
        rewind($stream);
        $storage->writeStream($path, $stream);

        $media = new Media($mediaId, 'local', $path, 'cover.png', 'image/png', 'png', \strlen(self::IMG), MediaVisibility::PUBLIC, $ownerId);
        self::getContainer()->get(MediaRepository::class)->save($media);

        $ebookId = Uuid::v7();
        $ebook = new Ebook($ebookId, $ownerId, 'Tytuł z okładką', $slug, 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        $ebook->assignCover($media);
        $ebook->publish(new \DateTimeImmutable());
        self::getContainer()->get(EbookRepository::class)->save($ebook);

        return $ebookId->toRfc4122();
    }
}
