<?php declare(strict_types=1);

namespace App\Ebook\Domain\Thumbnail;

/**
 * One requested variant: the target aspect ratio and the output width. The height
 * is derived downstream (by the media service) — the caller only ever states a
 * ratio and one side.
 */
final readonly class ThumbnailSpec
{
    public function __construct(
        public int $aspectW,
        public int $aspectH,
        public int $width,
    ) {
    }
}
