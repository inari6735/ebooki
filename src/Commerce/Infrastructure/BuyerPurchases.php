<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Read model of a buyer's own purchases, for the account panel. Only completed
 * orders (paid / fulfilled / refunded) are shown — abandoned or in-progress
 * checkouts are noise, not purchases. Joins the catalog for the current slug so
 * the buyer can jump back to the product page.
 */
final readonly class BuyerPurchases
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forBuyer(Uuid $buyerId): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id, o.title, o.total_amount, o.currency, o.status, o.placed_at,
                       o.ebook_id, e.slug AS ebook_slug
                FROM commerce_orders o
                LEFT JOIN ebooks e ON e.id = o.ebook_id
                WHERE o.buyer_id = :buyer
                  AND o.status IN ('paid', 'fulfilled', 'refunded')
                ORDER BY o.placed_at DESC
                SQL,
            ['buyer' => $buyerId->toRfc4122()],
            ['buyer' => ParameterType::STRING],
        );
    }
}
