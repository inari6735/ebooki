<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Projection;

use App\Commerce\Domain\Order\Event\PaymentConfirmed;
use App\Commerce\Domain\Order\Event\PaymentFailed;
use App\Commerce\Domain\Order\Event\PaymentInitiated;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Synchronous read model of payment attempts (one per order in the MVP), folded
 * from the order's payment events. Payment-specific columns only — the amount
 * lives on commerce_orders (joined when needed), so nothing is duplicated. All
 * writes are idempotent.
 */
final readonly class PaymentsProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onPaymentInitiated(PaymentInitiated $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO commerce_payments
                    (order_id, provider, session_id, status, initiated_at)
                VALUES (:order_id, :provider, :session_id, 'initiated', :initiated_at)
                ON CONFLICT (order_id) DO NOTHING
                SQL,
            [
                'order_id' => $event->orderId->toRfc4122(),
                'provider' => $event->provider,
                'session_id' => $event->sessionId,
                'initiated_at' => $event->occurredAt()->format('Y-m-d H:i:s.uP'),
            ],
            [
                'order_id' => ParameterType::STRING,
                'provider' => ParameterType::STRING,
                'session_id' => ParameterType::STRING,
                'initiated_at' => ParameterType::STRING,
            ],
        );
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onPaymentConfirmed(PaymentConfirmed $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE commerce_payments
                SET status = 'confirmed', provider_order_id = :provider_order_id,
                    method = :method, confirmed_at = :confirmed_at
                WHERE order_id = :order_id
                SQL,
            [
                'order_id' => $event->orderId->toRfc4122(),
                'provider_order_id' => $event->providerOrderId,
                'method' => $event->method,
                'confirmed_at' => $event->occurredAt()->format('Y-m-d H:i:s.uP'),
            ],
            [
                'order_id' => ParameterType::STRING,
                'provider_order_id' => ParameterType::STRING,
                'method' => ParameterType::STRING,
                'confirmed_at' => ParameterType::STRING,
            ],
        );
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onPaymentFailed(PaymentFailed $event): void
    {
        $this->connection->executeStatement(
            "UPDATE commerce_payments SET status = 'failed' WHERE order_id = :order_id",
            ['order_id' => $event->orderId->toRfc4122()],
            ['order_id' => ParameterType::STRING],
        );
    }
}
