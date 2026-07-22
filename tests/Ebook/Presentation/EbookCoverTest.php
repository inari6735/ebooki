<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Media;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaVisibility;
use App\Ebook\Domain\Pricing\Pricing;
use App\Ebook\Domain\Storage\FileStorage;
use App\Ebook\Domain\Thumbnail\CoverThumbnailProfile;
use App\Ebook\Domain\Thumbnail\GeneratedThumbnail;
use App\Ebook\Infrastructure\MediaThumbnailRepository;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * eBook covers are streamed from storage. A bare request serves the original; a
 * ?w=<width> request serves that exact generated thumbnail (or 404 — never a
 * different image). Listing tiles build their srcset from the widths that exist.
 */
final class EbookCoverTest extends WebTestCase
{
    private const string IMG = 'PNG-fake-cover-bytes-\x89';
    private const string THUMB = 'WEBP-fake-thumbnail-bytes';

    public function testServesOriginalCover(): void
    {
        $client = self::createClient();
        $id = $this->makeEbookWithCover('okladka-test');

        $client->request('GET', 'https://localhost/ebook/okladka/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame((string) \strlen(self::IMG), $client->getResponse()->headers->get('Content-Length'));
    }

    public function testServesExactThumbnailForWidth(): void
    {
        $client = self::createClient();
        $id = $this->makeEbookWithCover('okladka-thumb');

        $client->request('GET', 'https://localhost/ebook/okladka/'.$id.'?w=320');

        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame((string) \strlen(self::THUMB), $client->getResponse()->headers->get('Content-Length'));
    }

    public function testUnknownThumbnailWidthIs404(): void
    {
        $client = self::createClient();
        $id = $this->makeEbookWithCover('okladka-brak-rozmiaru');

        // A size that was never generated must 404 — no fallback to the original.
        $client->request('GET', 'https://localhost/ebook/okladka/'.$id.'?w=999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCatalogCardRendersCoverSrcset(): void
    {
        $client = self::createClient();
        $id = $this->makeEbookWithCover('okladka-na-liscie');

        $client->request('GET', 'https://localhost/ebooki');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // The tile offers every generated width, so each cover URL is present.
        foreach (CoverThumbnailProfile::WIDTHS as $w) {
            self::assertStringContainsString('/ebook/okladka/'.$id.'?w='.$w, $html);
        }
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
        $client->request('GET', 'https://localhost/ebook/okladka/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    private function makeEbookWithCover(string $slug): string
    {
        $container = self::getContainer();
        $ownerId = Uuid::v7();
        $container->get(CommandBus::class)->dispatch(
            new RegisterUser($ownerId->toRfc4122(), 'owner-'.$ownerId->toRfc4122().'@example.com', 'password123'),
        );

        $mediaId = Uuid::v7();
        $base = 'covers/'.$mediaId->toRfc4122();
        $storage = $container->get(FileStorage::class);
        $storage->writeStream($base.'.png', $this->streamOf(self::IMG));

        $media = new Media($mediaId, 'local', $base.'.png', 'cover.png', 'image/png', 'png', \strlen(self::IMG), MediaVisibility::PUBLIC, $ownerId);
        $container->get(MediaRepository::class)->save($media);

        // Simulate the media service having produced the variants: write the files
        // and record them in the projection (as the async handler would).
        $variants = [];
        foreach (CoverThumbnailProfile::WIDTHS as $w) {
            $key = $base.'_w'.$w.'.webp';
            $storage->writeStream($key, $this->streamOf(self::THUMB));
            $height = (int) ($w * CoverThumbnailProfile::ASPECT_H / CoverThumbnailProfile::ASPECT_W);
            $variants[] = new GeneratedThumbnail($key, $w, $height, \strlen(self::THUMB));
        }
        $container->get(MediaThumbnailRepository::class)->replaceForMedia($mediaId, CoverThumbnailProfile::FORMAT, $variants);

        $ebookId = Uuid::v7();
        $ebook = new Ebook($ebookId, $ownerId, 'Tytuł z okładką', $slug, 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        $ebook->assignCover($media);
        $ebook->publish(new \DateTimeImmutable());
        $container->get(EbookRepository::class)->save($ebook);

        return $ebookId->toRfc4122();
    }

    /** @return resource */
    private function streamOf(string $data)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $data);
        rewind($stream);

        return $stream;
    }
}
