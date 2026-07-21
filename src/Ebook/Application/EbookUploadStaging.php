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
use Symfony\Component\Uid\UuidV7;

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

    public function stage(UploadedFile $file, MediaVisibility $visibility, ?Uuid $ownerId, ?string $checksum = null): Media
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
            checksum: $checksum ?? hash_file('sha256', $file->getPathname()),
            status: MediaStatus::PENDING,
        );
        $this->media->save($media);

        return $media;
    }

    /**
     * Store one chunk of a large upload as its own idempotent part file:
     * `staging/chunks/{uploadId}/{index}.part`. Re-sending the same index simply
     * overwrites it (so a retried chunk never duplicates bytes), and the order in
     * which chunks arrive is irrelevant — {@see assembleChunks} reads by index.
     */
    public function putChunk(string $uploadId, int $index, UploadedFile $chunk): void
    {
        $stream = fopen($chunk->getPathname(), 'rb');
        try {
            $this->storage->writeStream($this->chunkPath($uploadId, $index), $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Reassemble the parts of a chunked upload into a single staged blob. The file
     * only ever materialises if EVERY part is present and the assembled byte count
     * matches what the client declared — a partial transfer therefore never yields
     * a usable Media, it throws. On success the chunk directory is deleted; on
     * failure it is left for {@see pruneStaleChunks} (or a retry).
     *
     * @throws \RuntimeException with a user-facing message when the upload is incomplete
     */
    public function assembleChunks(
        string $uploadId,
        int $totalChunks,
        string $originalName,
        string $mimeType,
        int $declaredSize,
        MediaVisibility $visibility,
        ?Uuid $ownerId,
    ): Media {
        for ($i = 0; $i < $totalChunks; ++$i) {
            if (!$this->storage->fileExists($this->chunkPath($uploadId, $i))) {
                throw new \RuntimeException('Przesyłanie przerwane — brakuje fragmentów pliku. Spróbuj ponownie.');
            }
        }

        $assembled = tmpfile();
        if (false === $assembled) {
            throw new \RuntimeException('Nie udało się złożyć pliku — spróbuj ponownie.');
        }
        $assembledPath = stream_get_meta_data($assembled)['uri'];

        try {
            for ($i = 0; $i < $totalChunks; ++$i) {
                $part = $this->storage->readStream($this->chunkPath($uploadId, $i));
                try {
                    stream_copy_to_stream($part, $assembled);
                } finally {
                    if (\is_resource($part)) {
                        fclose($part);
                    }
                }
            }
            fflush($assembled);

            $size = fstat($assembled)['size'];
            if ($size !== $declaredSize) {
                throw new \RuntimeException('Plik przesłał się niekompletnie — spróbuj ponownie.');
            }

            // Hash before writing: writeStream may consume/close the handle (and a
            // closed tmpfile() is unlinked), so read the checksum while it's intact.
            $checksum = hash_file('sha256', $assembledPath);

            $id = Uuid::v7();
            $extension = strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
            $path = sprintf('staging/%s.%s', $id->toRfc4122(), $extension);

            rewind($assembled);
            $this->storage->writeStream($path, $assembled);

            $media = new Media(
                $id,
                $this->disk,
                $path,
                $originalName,
                $mimeType,
                $extension,
                $size,
                $visibility,
                $ownerId,
                checksum: $checksum,
                status: MediaStatus::PENDING,
            );
            $this->media->save($media);
        } finally {
            if (\is_resource($assembled)) {
                fclose($assembled);
            }
        }

        $this->abortChunks($uploadId);

        return $media;
    }

    /** Drop all parts of an (abandoned or rejected) chunked upload. */
    public function abortChunks(string $uploadId): void
    {
        try {
            $this->storage->deleteDirectory('staging/chunks/'.$uploadId);
        } catch (\Throwable) {
            // Best-effort — a missing directory is already the desired state.
        }
    }

    /**
     * Delete chunk directories from uploads abandoned before finalisation. Their
     * age is read straight from the time-ordered UUIDv7 upload id, so no directory
     * metadata is needed. A generous threshold (hours) is safe: no real upload
     * runs that long, so an in-flight transfer is never collected.
     */
    public function pruneStaleChunks(\DateTimeImmutable $before): int
    {
        $count = 0;
        foreach ($this->storage->directories('staging/chunks') as $dir) {
            $uploadId = Uuid::isValid($name = basename($dir)) ? Uuid::fromString($name) : null;
            if (!$uploadId instanceof UuidV7 || $uploadId->getDateTime() >= $before) {
                continue;
            }
            $this->storage->deleteDirectory($dir);
            ++$count;
        }

        return $count;
    }

    private function chunkPath(string $uploadId, int $index): string
    {
        return sprintf('staging/chunks/%s/%d.part', $uploadId, $index);
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
