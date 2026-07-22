<?php declare(strict_types=1);

namespace App\Tests\Commerce\Presentation;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookFile;
use App\Ebook\Domain\EbookFileFormat;
use App\Ebook\Domain\EbookFileRole;
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
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Downloading a purchased eBook: only a logged-in buyer holding an entitlement may
 * fetch the file; everyone else gets an indistinguishable 404. The file is streamed
 * from storage.
 */
final class DownloadTest extends WebTestCase
{
    private const string PDF = '%PDF-1.4 fake ebook body';

    public function testEntitledBuyerDownloadsFile(): void
    {
        $client = self::createClient();
        $sellerId = $this->register('seller@example.com');
        $buyerId = $this->register('buyer@example.com');
        $ebookId = $this->makeEbookWithFile('zen-php', $sellerId);
        $this->grantEntitlement($buyerId, $ebookId);
        $this->login($client, 'buyer@example.com');

        $client->request('GET', 'https://localhost/pobierz/' . $ebookId->toRfc4122());

        self::assertResponseIsSuccessful();
        $headers = $client->getResponse()->headers;
        self::assertSame('application/pdf', $headers->get('Content-Type'));
        self::assertStringContainsString('zen-php.pdf', (string) $headers->get('Content-Disposition'));
        self::assertSame((string) \strlen(self::PDF), $headers->get('Content-Length'));
    }

    public function testWithoutEntitlementIs404(): void
    {
        $client = self::createClient();
        $sellerId = $this->register('seller@example.com');
        $this->register('intruder@example.com');
        $ebookId = $this->makeEbookWithFile('zen-php', $sellerId);
        $this->login($client, 'intruder@example.com');

        $client->request('GET', 'https://localhost/pobierz/' . $ebookId->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $client = self::createClient();
        $sellerId = $this->register('seller@example.com');
        $ebookId = $this->makeEbookWithFile('zen-php', $sellerId);

        $client->request('GET', 'https://localhost/pobierz/' . $ebookId->toRfc4122());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
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

    private function makeEbookWithFile(string $slug, Uuid $sellerId): Uuid
    {
        $mediaId = Uuid::v7();
        $path = 'downloads/' . $mediaId->toRfc4122() . '.pdf';

        $storage = self::getContainer()->get(FileStorage::class);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, self::PDF);
        rewind($stream);
        $storage->writeStream($path, $stream);

        $media = new Media($mediaId, 'local', $path, 'zen-php.pdf', 'application/pdf', 'pdf', strlen(self::PDF), MediaVisibility::PRIVATE, $sellerId);
        self::getContainer()->get(MediaRepository::class)->save($media);

        $ebookId = Uuid::v7();
        $ebook = new Ebook($ebookId, $sellerId, 'Zen PHP', $slug, 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        $ebook->addFile(new EbookFile(Uuid::v7(), $ebook, $media, EbookFileFormat::PDF, EbookFileRole::FULL, true));
        $ebook->publish(new \DateTimeImmutable());
        self::getContainer()->get(EbookRepository::class)->save($ebook);

        return $ebookId;
    }

    private function grantEntitlement(Uuid $buyerId, Uuid $ebookId): void
    {
        self::getContainer()->get(Connection::class)->insert('commerce_entitlements', [
            'id' => Uuid::v7()->toRfc4122(),
            'buyer_id' => $buyerId->toRfc4122(),
            'ebook_id' => $ebookId->toRfc4122(),
            'order_id' => Uuid::v7()->toRfc4122(),
            'granted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
        ]);
    }
}
