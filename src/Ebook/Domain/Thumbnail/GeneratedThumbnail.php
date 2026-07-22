<?php declare(strict_types=1);

namespace App\Ebook\Domain\Thumbnail;

/**
 * One variant the media service actually produced and stored: where it lives
 * (opaque storage key) and its real dimensions. Persisted verbatim into
 * media_thumbnails so serving never has to guess keys.
 */
final readonly class GeneratedThumbnail
{
    public function __construct(
        public string $storageKey,
        public int $width,
        public int $height,
        public int $sizeBytes,
    ) {
    }
}
