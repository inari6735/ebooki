<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Commerce\Domain\Order\OrderRepository;
use App\Commerce\Domain\Order\OrderStatus;
use App\Commerce\Domain\Payment\PaymentGateway;
use App\Commerce\Domain\Payment\RefundRequest;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Uid\Uuid;

/**
 * Full refund of an order (admin action). Asks the provider to return the money
 * OUTSIDE any DB transaction, then records the refund transactionally — which
 * reverses the ledger and revokes the buyer's entitlement. Idempotent: an
 * already-refunded order is a no-op.
 */
final readonly class RefundOrder
{
    public function __construct(
        private OrderRepository $orders,
        private PaymentGateway $gateway,
        private CommandBus $commandBus,
    ) {
    }

    public function __invoke(Uuid $orderId, string $reason): void
    {
        $order = $this->orders->get($orderId);

        if (OrderStatus::REFUNDED === $order->status()) {
            return;
        }
        if (!\in_array($order->status(), [OrderStatus::PAID, OrderStatus::FULFILLED], true)) {
            throw new \DomainException(sprintf('Only a paid order can be refunded; this one is %s.', $order->status()->value));
        }

        $this->gateway->refund(new RefundRequest(
            sessionId: $order->sessionId() ?? $orderId->toRfc4122(),
            providerOrderId: (string) $order->providerOrderId(),
            amount: $order->total(),
        ));

        $this->commandBus->dispatch(new MarkOrderRefunded($orderId->toRfc4122(), $reason));
    }
}
