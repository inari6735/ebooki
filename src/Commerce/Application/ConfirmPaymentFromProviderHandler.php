<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Commerce\Domain\Order\OrderRepository;
use App\Commerce\Domain\Order\OrderStatus;
use App\Commerce\Domain\Payment\PaymentGateway;
use App\Commerce\Domain\Payment\PaymentVerification;
use App\Shared\Domain\Money\Money;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Confirms an order's payment: cross-checks the notified amount against the order
 * total (server truth), verifies with the provider, and only then marks the order
 * paid. Idempotent — an already-paid order short-circuits, so redelivered
 * notifications never double-process. A verify failure throws, letting Messenger
 * retry (transient) or route to the failure transport (persistent); the order is
 * never marked paid on failure.
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class ConfirmPaymentFromProviderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private PaymentGateway $gateway,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConfirmPaymentFromProvider $command): void
    {
        $order = $this->orders->get(Uuid::fromString($command->orderId));

        if (OrderStatus::PAID === $order->status()) {
            return;
        }

        $expected = $order->total();
        $notified = Money::of($command->amountMinor, $command->currency);
        if (!$notified->equals($expected)) {
            throw new \DomainException(sprintf(
                'Notified amount %s does not match order total %s for %s.',
                $notified->format(),
                $expected->format(),
                $command->orderId,
            ));
        }

        $this->gateway->verify(new PaymentVerification(
            sessionId: $order->sessionId() ?? $command->orderId,
            providerOrderId: $command->providerOrderId,
            amount: $expected,
        ));

        $order->confirmPayment($command->providerOrderId, $expected, $command->method, $this->clock->now());
        $this->orders->save($order);
    }
}
