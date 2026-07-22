<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Projection;

use App\Commerce\Domain\Order\Event\OrderFulfilled;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Grants (and records) the buyer's permanent entitlement to a purchased eBook —
 * the authority the download endpoint checks. Built synchronously from
 * {@see OrderFulfilled}. A buyer owns an eBook at most once
 * (UNIQUE(buyer_id, ebook_id)); repeated/redelivered events are no-ops.
 */
final readonly class EntitlementProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onOrderFulfilled(OrderFulfilled $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO commerce_entitlements (id, buyer_id, ebook_id, order_id, granted_at)
                VALUES (:id, :buyer_id, :ebook_id, :order_id, :granted_at)
                ON CONFLICT (buyer_id, ebook_id) DO NOTHING
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'buyer_id' => $event->buyerId->toRfc4122(),
                'ebook_id' => $event->ebookId->toRfc4122(),
                'order_id' => $event->orderId->toRfc4122(),
                'granted_at' => $event->occurredAt()->format('Y-m-d H:i:s.uP'),
            ],
            [
                'id' => ParameterType::STRING,
                'buyer_id' => ParameterType::STRING,
                'ebook_id' => ParameterType::STRING,
                'order_id' => ParameterType::STRING,
                'granted_at' => ParameterType::STRING,
            ],
        );
    }
}
