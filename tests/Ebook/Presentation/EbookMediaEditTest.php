<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Editing an eBook's files reuses the wizard's uploader (scoped by ctx = eBook id).
 * On save the eBook's files are reconciled: removed ones are deleted, newly-staged
 * ones committed. A cancelled edit (no save) leaves the eBook untouched.
 */
final class EbookMediaEditTest extends WebTestCase
{
    public function testRemovingAFileDeletesItOnSave(): void
    {
        $client = self::createClient();
        $ebook = $this->seed($client, ['pdf', 'epub']);
        $id = $ebook->getId()->toRfc4122();
        $epubMediaId = $this->mediaIdOfFormat($ebook, 'epub');

        $crawler = $client->request('GET', 'https://localhost/panel/ebook/'.$id.'/edytuj');
        $uploadToken = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');

        // Remove the EPUB in the edit workspace — the committed blob is kept until save.
        $client->request('POST', 'https://localhost/wystaw-ebook/plik/'.$epubMediaId.'/usun', ['_token' => $uploadToken, 'ctx' => $id]);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->em()->find(Media::class, Uuid::fromString($epubMediaId))); // not yet gone

        $this->save($client, $id, $this->editToken($crawler));

        $fresh = $this->reload($id);
        self::assertSame(['pdf'], $this->formatsOf($fresh));
        self::assertNull($this->em()->find(Media::class, Uuid::fromString($epubMediaId))); // deleted on save
    }

    public function testAddingAFileCommitsItOnSave(): void
    {
        $client = self::createClient();
        $ebook = $this->seed($client, ['pdf']);
        $id = $ebook->getId()->toRfc4122();

        $crawler = $client->request('GET', 'https://localhost/panel/ebook/'.$id.'/edytuj');
        $uploadToken = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');

        // Stage a new EPUB into the edit workspace (chunked, ctx-scoped).
        $this->stage($client, $uploadToken, $id, 'nowy.epub', 'EPUB-CONTENT');

        $this->save($client, $id, $this->editToken($crawler));

        self::assertSame(['pdf', 'epub'], $this->formatsOf($this->reload($id)));
    }

    public function testRemovingTheLastFileBlocksSaveWithAMessage(): void
    {
        $client = self::createClient();
        $ebook = $this->seed($client, ['pdf']);
        $id = $ebook->getId()->toRfc4122();

        $crawler = $client->request('GET', 'https://localhost/panel/ebook/'.$id.'/edytuj');
        $uploadToken = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');
        $client->request('POST', 'https://localhost/wystaw-ebook/plik/'.$this->mediaIdOfFormat($ebook, 'pdf').'/usun', ['_token' => $uploadToken, 'ctx' => $id]);

        $client->request('POST', 'https://localhost/panel/ebook/'.$id.'/edytuj', [
            'ebook_edit' => [
                'title' => 'Poradnik', 'author' => 'Autor', 'category' => 'kategoria-testowa', 'language' => 'pl',
                'shortDescription' => 'Krótki opis', 'description' => 'Dłuższy opis.', 'price' => '40', '_token' => $this->editToken($crawler),
            ],
        ]);

        self::assertResponseStatusCodeSame(422); // not saved, and rendered so the message shows
        self::assertSelectorTextContains('body', 'musi mieć przynajmniej jeden plik');
        self::assertSame(['pdf'], $this->formatsOf($this->reload($id))); // eBook untouched
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** @param list<string> $formats */
    private function seed(KernelBrowser $client, array $formats): Ebook
    {
        $userId = Uuid::v7();
        self::getContainer()->get(CommandBus::class)->dispatch(new RegisterUser($userId->toRfc4122(), 'author@example.com', 'password123'));
        $client->request('GET', 'https://localhost/login');
        $client->submitForm('Zaloguj się', ['email' => 'author@example.com', 'password' => 'password123']);

        self::getContainer()->get(EntityManagerInterface::class)->persist(new \App\Ebook\Domain\Category(Uuid::v7(), 'Kategoria testowa', 'kategoria-testowa'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $ebook = new Ebook(Uuid::v7(), $userId, 'Poradnik', 'poradnik', 'Autor', 'pl', Pricing::fixed(Money::of(4000, Currency::PLN)));
        $ebook->publish(new \DateTimeImmutable());
        $first = true;
        foreach ($formats as $fmt) {
            $media = new Media(Uuid::v7(), 'local', 'ebooks/'.$ebook->getId()->toRfc4122().'/'.$fmt.'.'.$fmt, $fmt.'.'.$fmt, 'application/octet-stream', $fmt, 1000, MediaVisibility::PRIVATE, $userId, checksum: 'sum-'.$fmt, status: MediaStatus::READY);
            self::getContainer()->get(MediaRepository::class)->save($media);
            $ebook->addFile(new EbookFile(Uuid::v7(), $ebook, $media, EbookFileFormat::from($fmt), EbookFileRole::FULL, $first));
            $first = false;
        }
        self::getContainer()->get(EbookRepository::class)->save($ebook);

        return $ebook;
    }

    private function stage(KernelBrowser $client, string $token, string $ctx, string $name, string $content): void
    {
        $size = \strlen($content);
        $client->request('POST', 'https://localhost/wystaw-ebook/plik/init', ['_token' => $token, 'ctx' => $ctx, 'name' => $name, 'size' => $size]);
        $uploadId = json_decode((string) $client->getResponse()->getContent(), true)['uploadId'];

        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $content);
        $client->request('POST', 'https://localhost/wystaw-ebook/plik/chunk', ['_token' => $token, 'ctx' => $ctx, 'uploadId' => $uploadId, 'index' => 0], ['chunk' => new UploadedFile($path, 'c.part', 'application/octet-stream', null, true)]);

        $client->request('POST', 'https://localhost/wystaw-ebook/plik/finalize', ['_token' => $token, 'ctx' => $ctx, 'uploadId' => $uploadId, 'total' => 1, 'name' => $name, 'size' => $size, 'mime' => 'application/octet-stream']);
        self::assertResponseStatusCodeSame(201);
    }

    private function save(KernelBrowser $client, string $id, string $editToken): void
    {
        $client->request('POST', 'https://localhost/panel/ebook/'.$id.'/edytuj', [
            'ebook_edit' => [
                'title' => 'Poradnik', 'author' => 'Autor', 'category' => 'kategoria-testowa', 'language' => 'pl',
                'shortDescription' => 'Krótki opis', 'description' => 'Dłuższy opis.', 'price' => '40', '_token' => $editToken,
            ],
        ]);
        self::assertResponseRedirects('/panel/wystawione');
    }

    private function editToken(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return $crawler->filter('input[name="ebook_edit[_token]"]')->attr('value');
    }

    private function mediaIdOfFormat(Ebook $ebook, string $format): string
    {
        foreach ($ebook->files() as $file) {
            if ($format === $file->getFormat()->value) {
                return $file->getMedia()->getId()->toRfc4122();
            }
        }
        self::fail('No file of format '.$format);
    }

    /** @return list<string> */
    private function formatsOf(Ebook $ebook): array
    {
        $formats = array_map(static fn (EbookFile $f): string => $f->getFormat()->value, $ebook->files());
        sort($formats);

        return array_reverse($formats); // pdf before epub for a stable assertion
    }

    private function reload(string $id): Ebook
    {
        $this->em()->clear();

        return self::getContainer()->get(EbookRepository::class)->get(Uuid::fromString($id));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');
        parent::tearDown();
    }
}
