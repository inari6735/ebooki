<?php declare(strict_types=1);

namespace App\Tests\Ebook\Application;

use App\Ebook\Application\EbookUploadStaging;
use App\Ebook\Application\GenerateCoverThumbnails;
use App\Ebook\Application\PublishEbookFromWizard;
use App\Ebook\Domain\MediaVisibility;
use App\Ebook\Domain\Storage\FileStorage;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Publishing an ebook with a cover must enqueue async thumbnail generation for
 * that cover's media — and nothing else. The message is routed to the async
 * (in-memory, in tests) transport, so we assert it was SENT, never handled here.
 */
final class CoverThumbnailDispatchTest extends KernelTestCase
{
    public function testPublishingWithCoverEnqueuesThumbnailGeneration(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $ownerId = Uuid::v7();
        $container->get(CommandBus::class)->dispatch(
            new RegisterUser($ownerId->toRfc4122(), 'cover-seller@example.com', 'password123'),
        );

        /** @var EbookUploadStaging $staging */
        $staging = $container->get(EbookUploadStaging::class);
        $cover = $staging->stage($this->uploadedPng(), MediaVisibility::PRIVATE, $ownerId);

        $data = new PublishEbookData();
        $data->title = 'eBook z okładką';
        $data->author = 'Jan Testowy';
        $data->language = 'pl';
        $data->shortDescription = 'Krótki opis';
        $data->description = 'Dłuższy opis.';
        $data->price = 20.0;
        $data->coverMediaId = $cover->getId()->toRfc4122();
        $data->files = [];

        $ebook = ($container->get(PublishEbookFromWizard::class))($data, $ownerId, true);

        // The async transport captured exactly one thumbnail job, for this cover.
        $sent = $container->get('messenger.transport.async')->getSent();
        $jobs = [];
        foreach ($sent as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof GenerateCoverThumbnails) {
                $jobs[] = $message;
            }
        }

        self::assertCount(1, $jobs, 'exactly one cover-thumbnail job must be enqueued');
        self::assertSame($cover->getId()->toRfc4122(), $jobs[0]->mediaId);

        // Cleanup the committed cover blob so the test disk stays clean.
        $cover = $ebook->getCover();
        if (null !== $cover) {
            $container->get(FileStorage::class)->delete($cover->getPath());
        }
    }

    private function uploadedPng(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cov');
        // Minimal valid 1x1 PNG.
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
        ));

        return new UploadedFile($path, 'cover.png', 'image/png', null, true);
    }
}
