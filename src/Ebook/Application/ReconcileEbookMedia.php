<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookFile;
use App\Ebook\Domain\EbookFileFormat;
use App\Ebook\Domain\EbookFileRole;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Media;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaStatus;
use App\Ebook\Domain\Storage\FileStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Brings a persisted eBook's files + cover in line with the edited workspace: removes
 * files that were dropped (and their blobs), commits + links newly-added staged files,
 * and swaps the cover. Removals are flushed before their blobs are deleted so the
 * primary-file uniqueness index is never momentarily violated and no FK dangles.
 */
final readonly class ReconcileEbookMedia
{
    public function __construct(
        private EbookRepository $ebooks,
        private MediaRepository $media,
        private EbookUploadStaging $staging,
        private FileStorage $storage,
    ) {
    }

    /**
     * @param list<array{mediaId: string, format: string, name?: string, size?: string, checksum?: string}> $desiredFiles
     */
    public function __invoke(Ebook $ebook, array $desiredFiles, ?string $desiredCoverId): void
    {
        $destination = 'ebooks/'.$ebook->getId()->toRfc4122();
        $desiredIds = array_column($desiredFiles, 'mediaId');

        // 1) Remove files no longer wanted; flush the deletions before dropping blobs.
        $removedBlobs = [];
        foreach ($ebook->files() as $file) {
            if (!\in_array($file->getMedia()->getId()->toRfc4122(), $desiredIds, true)) {
                $removedBlobs[] = $file->getMedia();
                $ebook->removeFile($file);
            }
        }
        if ([] !== $removedBlobs) {
            $this->ebooks->save($ebook);
            foreach ($removedBlobs as $media) {
                $this->deleteBlob($media);
            }
        }

        // 2) Add newly-staged files (commit them to the eBook's permanent home).
        $currentIds = array_map(
            static fn (EbookFile $f): string => $f->getMedia()->getId()->toRfc4122(),
            $ebook->files(),
        );
        foreach ($desiredFiles as $ref) {
            if (\in_array($ref['mediaId'], $currentIds, true)) {
                continue;
            }
            $media = $this->media->get(Uuid::fromString($ref['mediaId']));
            if (null === $media) {
                continue;
            }
            $this->commitIfPending($media, $destination);
            $ebook->addFile(new EbookFile(Uuid::v7(), $ebook, $media, EbookFileFormat::from($ref['format']), EbookFileRole::FULL, false));
        }
        $ebook->ensurePrimaryFile();

        // 3) Cover swap. Flush the new/cleared cover before deleting the old blob so
        //    the cover_media_id FK never points at a deleted row.
        $oldCover = null;
        $currentCoverId = $ebook->getCover()?->getId()->toRfc4122();
        if ($desiredCoverId !== $currentCoverId) {
            $oldCover = $ebook->getCover();
            if (null !== $desiredCoverId && null !== ($newCover = $this->media->get(Uuid::fromString($desiredCoverId)))) {
                $this->commitIfPending($newCover, $destination);
                $ebook->assignCover($newCover);
            } else {
                $ebook->assignCover(null);
            }
        }

        $this->ebooks->save($ebook);

        if (null !== $oldCover) {
            $this->deleteBlob($oldCover);
        }
    }

    private function commitIfPending(Media $media, string $destination): void
    {
        if (MediaStatus::PENDING === $media->getStatus()) {
            $this->staging->commit($media, $destination);
        }
    }

    private function deleteBlob(Media $media): void
    {
        try {
            $this->storage->delete($media->getPath());
        } catch (\Throwable) {
            // Best-effort: drop the row even if the blob is already gone.
        }
        $this->media->remove($media);
    }
}
