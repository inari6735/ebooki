<?php declare(strict_types=1);

namespace App\Tests\Ebook\Application;

use App\Ebook\Application\EbookUploadStaging;
use App\Ebook\Application\PublishEbookFromWizard;
use App\Ebook\Domain\EbookStatus;
use App\Ebook\Domain\MediaStatus;
use App\Ebook\Domain\MediaVisibility;
use App\Ebook\Domain\Storage\FileStorage;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * The heart of the "upload done wisely" flow: a file is staged when added, then
 * at finalize it is committed — moved from staging to the eBook's permanent home,
 * marked ready, and linked to a persisted Ebook.
 */
final class StagingCommitTest extends KernelTestCase
{
    public function testStagedFileIsCommittedAndEbookPersisted(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $ownerId = Uuid::v7();
        $container->get(CommandBus::class)->dispatch(
            new RegisterUser($ownerId->toRfc4122(), 'seller@example.com', 'password123'),
        );

        /** @var EbookUploadStaging $staging */
        $staging = $container->get(EbookUploadStaging::class);
        /** @var FileStorage $storage */
        $storage = $container->get(FileStorage::class);

        // 1. Add a file → it lands in staging as a pending Media.
        $media = $staging->stage($this->uploadedPdf(), MediaVisibility::PRIVATE, $ownerId);
        $stagingPath = $media->getPath();

        self::assertStringStartsWith('staging/', $stagingPath);
        self::assertSame(MediaStatus::PENDING, $media->getStatus());
        self::assertTrue($storage->fileExists($stagingPath));

        // 2. Finalize the wizard → commit.
        $data = new PublishEbookData();
        $data->title = 'Skuteczna produktywność';
        $data->author = 'Jan Testowy';
        $data->language = 'pl';
        $data->shortDescription = 'Krótki opis';
        $data->description = 'Dłuższy opis eBooka.';
        $data->price = 40.0;
        $data->files = [[
            'mediaId' => $media->getId()->toRfc4122(),
            'name' => 'book.pdf',
            'size' => '1 KB',
            'format' => 'pdf',
        ]];

        $ebook = ($container->get(PublishEbookFromWizard::class))($data, $ownerId, false);

        // 3. eBook persisted and published, with the file linked and marked ready.
        self::assertSame($ownerId->toRfc4122(), $ebook->getOwnerId()->toRfc4122());
        self::assertSame(EbookStatus::PUBLISHED, $ebook->getStatus());
        self::assertNotSame('', $ebook->getSlug());

        $files = $ebook->files();
        self::assertCount(1, $files);
        self::assertTrue($files[0]->isPrimary());

        $committed = $files[0]->getMedia();
        self::assertSame(MediaStatus::READY, $committed->getStatus());
        self::assertStringStartsWith('ebooks/'.$ebook->getId()->toRfc4122().'/', $committed->getPath());

        // 4. The blob really moved: gone from staging, present at the new key.
        self::assertFalse($storage->fileExists($stagingPath), 'staged blob must be moved out of staging');
        self::assertTrue($storage->fileExists($committed->getPath()), 'committed blob must exist at its permanent key');

        $storage->delete($committed->getPath()); // keep the test dir clean
    }

    private function uploadedPdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ebk');
        file_put_contents($path, "%PDF-1.4 test\n");

        // $test = true bypasses the is_uploaded_file() check.
        return new UploadedFile($path, 'book.pdf', 'application/pdf', null, true);
    }
}
