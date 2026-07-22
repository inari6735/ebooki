<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order;

use App\Commerce\Domain\Order\Event\OrderFulfilled;
use App\Commerce\Domain\Order\Event\OrderPlaced;
use App\Commerce\Domain\Order\Event\OrderRefunded;
use App\Commerce\Domain\Order\Event\PaymentConfirmed;
use App\Commerce\Domain\Order\Event\PaymentFailed;
use App\Commerce\Domain\Order\Event\PaymentInitiated;
use App\Shared\Domain\EventSourcing\AggregateRoot;
use App\Shared\Domain\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * The purchase aggregate — an event-sourced "buy now" order for one eBook.
 * All state is a fold over its events; behaviour records new events and never
 * mutates fields directly (see {@see AggregateRoot}). The author/platform split
 * is computed once, at placement, and captured on {@see OrderPlaced}.
 */
final class Order extends AggregateRoot
{
    /** 100% of a sale, in basis points (author keeps FULL_BPS − commission). */
    private const int FULL_BPS = 10_000;

    private Uuid $id;
    private Uuid $buyerId;
    private OrderItem $item;
    private Money $authorEarnings;
    private Money $platformFee;
    private int $commissionBps;
    private bool $withdrawalConsent;
    private OrderStatus $status;
    private ?string $provider = null;
    private ?string $sessionId = null;
    private ?string $providerOrderId = null;

    /**
     * Place an order for one eBook. The buyer paid nothing yet — this only
     * records the intent + the frozen price/commission snapshot. Digital goods
     * require the buyer's explicit consent to immediate delivery (waiving the
     * 14-day withdrawal right) before any payment is initiated.
     */
    public static function place(
        Uuid $orderId,
        Uuid $buyerId,
        OrderItem $item,
        int $commissionBps,
        bool $withdrawalConsent,
        \DateTimeImmutable $placedAt,
    ): self {
        if (!$item->unitPrice->isPositive()) {
            throw new \InvalidArgumentException('An order requires a positive price; free eBooks are not purchased.');
        }
        if ($commissionBps < 0 || $commissionBps > self::FULL_BPS) {
            throw new \InvalidArgumentException(sprintf('Commission must be between 0 and %d bps.', self::FULL_BPS));
        }
        if (!$withdrawalConsent) {
            throw new \DomainException('Consent to immediate delivery is required to place the order.');
        }

        [$authorEarnings, $platformFee] = $item->unitPrice->allocate(
            self::FULL_BPS - $commissionBps,
            $commissionBps,
        );

        $order = new self();
        $order->recordThat(new OrderPlaced(
            orderId: $orderId,
            buyerId: $buyerId,
            ebookId: $item->ebookId,
            sellerId: $item->sellerId,
            title: $item->title,
            unitPrice: $item->unitPrice,
            authorEarnings: $authorEarnings,
            platformFee: $platformFee,
            commissionBps: $commissionBps,
            withdrawalConsent: $withdrawalConsent,
            placedAt: $placedAt,
        ));

        return $order;
    }

    /**
     * A payment transaction was registered at the provider for this order.
     * `sessionId` is our correlation id sent to the provider; `token` is the
     * provider's redirect token.
     */
    public function initiatePayment(string $provider, string $sessionId, string $token, \DateTimeImmutable $now): void
    {
        if (OrderStatus::PENDING !== $this->status) {
            throw new \DomainException(sprintf('Cannot initiate payment for an order that is %s.', $this->status->value));
        }

        $this->recordThat(new PaymentInitiated($this->id, $provider, $sessionId, $token, $now));
    }

    /**
     * The provider confirmed (and we verified) that the buyer paid. Idempotent:
     * a repeated confirmation for an already-paid order is a no-op, so redelivered
     * provider notifications never double-fulfil. The paid amount MUST match the
     * order total — a mismatch is a hard failure, never silently accepted.
     */
    public function confirmPayment(string $providerOrderId, Money $paidAmount, ?string $method, \DateTimeImmutable $now): void
    {
        // Already confirmed (possibly already fulfilled) — redelivered notification.
        if (\in_array($this->status, [OrderStatus::PAID, OrderStatus::FULFILLED], true)) {
            return;
        }
        if (OrderStatus::AWAITING_PAYMENT !== $this->status) {
            throw new \DomainException(sprintf('Cannot confirm payment for an order that is %s.', $this->status->value));
        }
        if (!$paidAmount->equals($this->total())) {
            throw new \DomainException(sprintf(
                'Paid amount %s does not match the order total %s.',
                $paidAmount->format(),
                $this->total()->format(),
            ));
        }

        $this->recordThat(new PaymentConfirmed($this->id, $providerOrderId, $paidAmount, $method, $now));
    }

    /**
     * Grant the buyer access to the purchased eBook. For a digital good this
     * happens right after payment confirmation. Idempotent; requires a paid order.
     */
    public function fulfill(\DateTimeImmutable $now): void
    {
        if (OrderStatus::FULFILLED === $this->status) {
            return;
        }
        if (OrderStatus::PAID !== $this->status) {
            throw new \DomainException(sprintf('Cannot fulfil an order that is %s.', $this->status->value));
        }

        $this->recordThat(new OrderFulfilled($this->id, $this->buyerId, $this->item->ebookId, $now));
    }

    /**
     * Refund the order (full refund in the MVP). Only a paid/fulfilled order can be
     * refunded; idempotent once refunded. Reverses the ledger and revokes access.
     */
    public function refund(string $reason, \DateTimeImmutable $now): void
    {
        if (OrderStatus::REFUNDED === $this->status) {
            return;
        }
        if (!\in_array($this->status, [OrderStatus::PAID, OrderStatus::FULFILLED], true)) {
            throw new \DomainException(sprintf('Only a paid order can be refunded; this one is %s.', $this->status->value));
        }

        $this->recordThat(new OrderRefunded($this->id, $this->total(), $reason, $now));
    }

    /** The payment did not go through. No-op if the order already failed; never overrides a paid order. */
    public function failPayment(string $reason, \DateTimeImmutable $now): void
    {
        if (OrderStatus::FAILED === $this->status) {
            return;
        }
        if (OrderStatus::PAID === $this->status) {
            throw new \DomainException('Cannot fail an order that is already paid.');
        }

        $this->recordThat(new PaymentFailed($this->id, $reason, $now));
    }

    public function aggregateId(): Uuid
    {
        return $this->id;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function total(): Money
    {
        return $this->item->unitPrice;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function providerOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    protected function applyOrderPlaced(OrderPlaced $event): void
    {
        $this->id = $event->orderId;
        $this->buyerId = $event->buyerId;
        $this->item = new OrderItem($event->ebookId, $event->sellerId, $event->title, $event->unitPrice);
        $this->authorEarnings = $event->authorEarnings;
        $this->platformFee = $event->platformFee;
        $this->commissionBps = $event->commissionBps;
        $this->withdrawalConsent = $event->withdrawalConsent;
        $this->status = OrderStatus::PENDING;
    }

    protected function applyPaymentInitiated(PaymentInitiated $event): void
    {
        $this->provider = $event->provider;
        $this->sessionId = $event->sessionId;
        $this->status = OrderStatus::AWAITING_PAYMENT;
    }

    protected function applyPaymentConfirmed(PaymentConfirmed $event): void
    {
        $this->providerOrderId = $event->providerOrderId;
        $this->status = OrderStatus::PAID;
    }

    protected function applyPaymentFailed(PaymentFailed $event): void
    {
        $this->status = OrderStatus::FAILED;
    }

    protected function applyOrderFulfilled(OrderFulfilled $event): void
    {
        $this->status = OrderStatus::FULFILLED;
    }

    protected function applyOrderRefunded(OrderRefunded $event): void
    {
        $this->status = OrderStatus::REFUNDED;
    }
}
