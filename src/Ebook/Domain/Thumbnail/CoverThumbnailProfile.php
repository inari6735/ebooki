<?php declare(strict_types=1);

namespace App\Ebook\Domain\Thumbnail;

/**
 * The single source of truth for cover thumbnails: 2:3 portrait at three widths
 * (200 / 320 / 480), WebP. The listing tile requests these same widths, so this
 * profile ties generation and serving together — change the sizes here and both
 * sides follow.
 */
final class CoverThumbnailProfile
{
    public const int ASPECT_W = 2;
    public const int ASPECT_H = 3;

    /** @var list<int> */
    public const array WIDTHS = [200, 320, 480];

    public const string FORMAT = 'webp';
    public const int QUALITY = 80;

    /** @return list<ThumbnailSpec> */
    public static function specs(): array
    {
        return array_map(
            static fn (int $width): ThumbnailSpec => new ThumbnailSpec(self::ASPECT_W, self::ASPECT_H, $width),
            self::WIDTHS,
        );
    }
}
