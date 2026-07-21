<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\Media;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaStatus;
use App\Ebook\Domain\MediaVisibility;
use App\Ebook\Domain\Storage\FileStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Handles the file lifecycle for the publish wizard: a file is written to a
 * temporary `staging/` area the moment it is added (with a `pending` Media row),
 * and only when the wizard is finalised (step 4) is it `commit()`-ed — moved to
 * its permanent home and marked `ready`. Abandoned staged files are pruned by
 * {@see \App\Ebook\Infrastructure\Console\PruneStagedMediaCommand}.
 */
final readonly class EbookUploadStaging
{
    public function __construct(
        private FileStorage $storage,
        private MediaRepository $media,
        #[Autowire('%app.ebook.storage_disk%')]
        private string $disk,
    ) {
    }

    public function stage(UploadedFile $file, MediaVisibility $visibility, ?Uuid $ownerId): Media
    {
        $id = Uuid::v7();
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? ''));
        $path = sprintf('staging/%s.%s', $id->toRfc4122(), $extension);

        $stream = fopen($file->getPathname(), 'rb');
        try {
            $this->storage->writeStream($path, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $media = new Media(
            $id,
            $this->disk,
            $path,
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            $extension,
            (int) $file->getSize(),
            $visibility,
            $ownerId,
            checksum: null,
            status: MediaStatus::PENDING,
        );
        $this->media->save($media);

        return $media;
    }

    /** Move a staged blob into $destinationDir and mark it ready. */
    public function commit(Media $media, string $destinationDir): void
    {
        $destination = sprintf('%s/%s.%s', rtrim($destinationDir, '/'), $media->getId()->toRfc4122(), $media->getExtension());
        $this->storage->move($media->getPath(), $destination);
        $media->relocate($destination);
        $media->markReady();
        $this->media->save($media);
    }

    /** @return resource */
    public function readStream(Media $media)
    {
        return $this->storage->readStream($media->getPath());
    }

    public function discard(Media $media): void
    {
        try {
            $this->storage->delete($media->getPath());
        } catch (\Throwable) {
            // Best-effort: drop the row even if the blob is already gone.
        }
        $this->media->remove($media);
    }
}
