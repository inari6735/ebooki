<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\Storage\FileStorage;

/**
 * Deletes an eBook and everything it owns: the aggregate (which cascade-removes its
 * EbookFile rows), then the Media rows those files + the cover pointed at, along
 * with their storage blobs. Media are collected before the aggregate is removed and
 * deleted after, once nothing references them anymore.
 */
final readonly class DeleteEbook
{
    public function __construct(
        private EbookRepository $ebooks,
        private MediaRepository $media,
        private FileStorage $storage,
    ) {
    }

    public function __invoke(Ebook $ebook): void
    {
        $blobs = [];
        foreach ($ebook->files() as $file) {
            $blobs[] = $file->getMedia();
        }
        if (null !== $cover = $ebook->getCover()) {
            $blobs[] = $cover;
        }

        $this->ebooks->remove($ebook);

        foreach ($blobs as $media) {
            try {
                $this->storage->delete($media->getPath());
            } catch (\Throwable) {
                // Best-effort: drop the row even if the blob is already gone.
            }
            $this->media->remove($media);
        }
    }
}
