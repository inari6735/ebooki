<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Commerce\Domain\Order\OrderRepository;
use App\Commerce\Domain\Order\OrderStatus;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Marks an order's payment as failed. Idempotent and never touches a settled
 * order: a late failure notification arriving after the payment already
 * completed/was refunded is ignored (so we neither throw — which would make
 * Messenger retry forever — nor revoke a legitimate payment).
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class FailPaymentFromProviderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(FailPaymentFromProvider $command): void
    {
        $order = $this->orders->get(Uuid::fromString($command->orderId));

        if (\in_array($order->status(), [OrderStatus::PAID, OrderStatus::FULFILLED, OrderStatus::REFUNDED], true)) {
            return;
        }

        $order->failPayment($command->reason, $this->clock->now());
        $this->orders->save($order);
    }
}
