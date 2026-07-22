<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure;

use App\Ebook\Domain\EbookStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read model for the public eBook catalogue (browse/listing). Returns only
 * PUBLISHED eBooks as plain display rows — read-optimised, no ORM hydration.
 * Sort keys are a fixed whitelist (never interpolated from the request).
 */
final readonly class EbookCatalog
{
    /** Sort key => ORDER BY clause. Effective price = promo ?? price, 0 for free. */
    private const array SORTS = [
        'newest'     => 'e.published_at DESC NULLS LAST, e.title ASC',
        'price_asc'  => '(CASE WHEN e.is_free THEN 0 ELSE COALESCE(e.promo_price_amount, e.price_amount, 0) END) ASC, e.title ASC',
        'price_desc' => '(CASE WHEN e.is_free THEN 0 ELSE COALESCE(e.promo_price_amount, e.price_amount, 0) END) DESC, e.title ASC',
        'title'      => 'e.title ASC',
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> available sort keys */
    public function sortKeys(): array
    {
        return array_keys(self::SORTS);
    }

    /** @return list<array<string, mixed>> */
    public function page(?string $categorySlug, string $sort, int $limit, int $offset): array
    {
        $order = self::SORTS[$sort] ?? self::SORTS['newest'];

        [$where, $params, $types] = $this->filter($categorySlug);
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        return $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT e.id, e.slug, e.title, e.author_name, e.short_description,
                       e.is_free::int AS is_free, e.pay_what_you_want::int AS pay_what_you_want,
                       e.price_amount, e.promo_price_amount, e.currency, e.cover_media_id,
                       e.published_at, c.name AS category_name, c.slug AS category_slug
                FROM ebooks e
                LEFT JOIN categories c ON c.id = e.category_id
                {$where}
                ORDER BY {$order}
                LIMIT :limit OFFSET :offset
                SQL,
            $params,
            $types,
        );
    }

    public function count(?string $categorySlug): int
    {
        [$where, $params, $types] = $this->filter($categorySlug);

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ebooks e LEFT JOIN categories c ON c.id = e.category_id {$where}",
            $params,
            $types,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function filter(?string $categorySlug): array
    {
        $where = 'WHERE e.status = :status';
        $params = ['status' => EbookStatus::PUBLISHED->value];
        $types = ['status' => ParameterType::STRING];

        if (null !== $categorySlug && '' !== $categorySlug) {
            $where .= ' AND c.slug = :cat';
            $params['cat'] = $categorySlug;
            $types['cat'] = ParameterType::STRING;
        }

        return [$where, $params, $types];
    }
}
