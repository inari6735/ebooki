<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure;

use App\Ebook\Domain\Thumbnail\GeneratedThumbnail;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Read/write access to `media_thumbnails` — a VOLATILE projection of the resized
 * cover variants stored in the media service. It is deliberately a plain DBAL
 * table with no ORM relation to Media: Media knows nothing about it, and it can be
 * rebuilt at any time. Written only by the thumbnail handler; read to build
 * <img srcset> for listing tiles.
 */
final readonly class MediaThumbnailRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Replace all variants of a media in one transaction, so regeneration
     * overwrites cleanly and the table always mirrors what the service holds.
     *
     * @param list<GeneratedThumbnail> $thumbnails
     */
    public function replaceForMedia(Uuid $mediaId, string $format, array $thumbnails): void
    {
        $this->connection->transactional(function (Connection $c) use ($mediaId, $format, $thumbnails): void {
            $c->executeStatement(
                'DELETE FROM media_thumbnails WHERE media_id = :media AND format = :format',
                ['media' => $mediaId->toRfc4122(), 'format' => $format],
            );
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
            foreach ($thumbnails as $t) {
                $c->insert('media_thumbnails', [
                    'media_id' => $mediaId->toRfc4122(),
                    'width' => $t->width,
                    'height' => $t->height,
                    'format' => $format,
                    'storage_key' => $t->storageKey,
                    'size_bytes' => $t->sizeBytes,
                    'created_at' => $now,
                ]);
            }
        });
    }

    /**
     * Available widths per media (rfc4122 id → ascending widths), for building
     * <img srcset>. The tile offers ONLY widths that exist here, so the browser
     * never requests a size that was not generated (srcset has no error-fallback).
     *
     * @param list<Uuid> $mediaIds
     *
     * @return array<string, list<int>>
     */
    public function widthsForMediaIds(array $mediaIds): array
    {
        if ([] === $mediaIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT media_id, width
                FROM media_thumbnails
                WHERE media_id IN (:ids)
                ORDER BY media_id, width
                SQL,
            ['ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $mediaIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        $widths = [];
        foreach ($rows as $r) {
            $widths[$r['media_id']][] = (int) $r['width'];
        }

        return $widths;
    }

    /**
     * The stored variant for one media at an EXACT width, or null if that size
     * was never generated. The serving route uses this to return that exact
     * thumbnail or a 404 — never a different image.
     *
     * @return array{key: string, size: int, format: string}|null
     */
    public function variant(Uuid $mediaId, int $width): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT storage_key, size_bytes, format
                FROM media_thumbnails
                WHERE media_id = :media AND width = :width
                LIMIT 1
                SQL,
            ['media' => $mediaId->toRfc4122(), 'width' => $width],
        );

        if (false === $row) {
            return null;
        }

        return ['key' => $row['storage_key'], 'size' => (int) $row['size_bytes'], 'format' => $row['format']];
    }
}
