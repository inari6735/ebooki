<?php declare(strict_types=1);

namespace App\Ebook\Domain\Thumbnail;

/**
 * Port to the image-resizing capability. The application asks for variants of a
 * stored source; the adapter (media service over gRPC) does the work and reports
 * back exactly what it wrote. The application never touches image bytes itself.
 */
interface ThumbnailGenerator
{
    /**
     * @param list<ThumbnailSpec> $specs
     *
     * @return list<GeneratedThumbnail>
     */
    public function generate(string $sourceKey, array $specs, string $format, int $quality): array;
}
