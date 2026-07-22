<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Projection;

use App\Commerce\Domain\Order\Event\OrderPlaced;
use App\Commerce\Domain\Order\Event\PaymentConfirmed;
use App\Commerce\Domain\Order\Event\PaymentFailed;
use App\Commerce\Domain\Order\Event\PaymentInitiated;
use App\Commerce\Domain\Order\OrderStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Synchronous read model of orders, folded from the order event stream. Powers
 * buyer/admin order views and payment analysis without replaying events. Runs
 * inside the emitting transaction (all order events are SyncTransport). Writes
 * are idempotent so redeliveries can never duplicate or corrupt a row.
 */
final readonly class OrdersProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO commerce_orders
                    (id, buyer_id, ebook_id, seller_id, title, currency,
                     total_amount, author_earnings, platform_fee, commission_bps,
                     status, placed_at)
                VALUES (:id, :buyer_id, :ebook_id, :seller_id, :title, :currency,
                        :total_amount, :author_earnings, :platform_fee, :commission_bps,
                        :status, :placed_at)
                ON CONFLICT (id) DO NOTHING
                SQL,
            [
                'id' => $event->orderId->toRfc4122(),
                'buyer_id' => $event->buyerId->toRfc4122(),
                'ebook_id' => $event->ebookId->toRfc4122(),
                'seller_id' => $event->sellerId->toRfc4122(),
                'title' => $event->title,
                'currency' => $event->unitPrice->currency->value,
                'total_amount' => $event->unitPrice->amount,
                'author_earnings' => $event->authorEarnings->amount,
                'platform_fee' => $event->platformFee->amount,
                'commission_bps' => $event->commissionBps,
                'status' => OrderStatus::PENDING->value,
                'placed_at' => $event->occurredAt()->format('Y-m-d H:i:s.uP'),
            ],
            [
                'id' => ParameterType::STRING,
                'buyer_id' => ParameterType::STRING,
                'ebook_id' => ParameterType::STRING,
                'seller_id' => ParameterType::STRING,
                'title' => ParameterType::STRING,
                'currency' => ParameterType::STRING,
                'total_amount' => ParameterType::INTEGER,
                'author_earnings' => ParameterType::INTEGER,
                'platform_fee' => ParameterType::INTEGER,
                'commission_bps' => ParameterType::INTEGER,
                'status' => ParameterType::STRING,
                'placed_at' => ParameterType::STRING,
            ],
        );
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onPaymentInitiated(PaymentInitiated $event): void
    {
        $this->setStatus($event->orderId->toRfc4122(), OrderStatus::AWAITING_PAYMENT);
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onPaymentConfirmed(PaymentConfirmed $event): void
    {
        $this->setStatus($event->orderId->toRfc4122(), OrderStatus::PAID);
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onPaymentFailed(PaymentFailed $event): void
    {
        $this->setStatus($event->orderId->toRfc4122(), OrderStatus::FAILED);
    }

    private function setStatus(string $orderId, OrderStatus $status): void
    {
        $this->connection->executeStatement(
            'UPDATE commerce_orders SET status = :status WHERE id = :id',
            ['status' => $status->value, 'id' => $orderId],
            ['status' => ParameterType::STRING, 'id' => ParameterType::STRING],
        );
    }
}
