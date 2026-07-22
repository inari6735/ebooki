<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the entitlement read model — the authority for "does this buyer own this
 * eBook?". Used to authorise downloads.
 */
final readonly class Entitlements
{
    public function __construct(private Connection $connection)
    {
    }

    public function owns(Uuid $buyerId, Uuid $ebookId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM commerce_entitlements WHERE buyer_id = ? AND ebook_id = ?',
            [$buyerId->toRfc4122(), $ebookId->toRfc4122()],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }
}
