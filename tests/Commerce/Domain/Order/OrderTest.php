<?php declare(strict_types=1);

namespace App\Tests\Commerce\Domain\Order;

use App\Commerce\Domain\Order\Event\OrderFulfilled;
use App\Commerce\Domain\Order\Event\OrderPlaced;
use App\Commerce\Domain\Order\Event\OrderRefunded;
use App\Commerce\Domain\Order\Event\PaymentConfirmed;
use App\Commerce\Domain\Order\Event\PaymentFailed;
use App\Commerce\Domain\Order\Event\PaymentInitiated;
use App\Commerce\Domain\Order\Order;
use App\Commerce\Domain\Order\OrderItem;
use App\Commerce\Domain\Order\OrderStatus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrderTest extends TestCase
{
    private function item(int $priceMinor = 2990): OrderItem
    {
        return new OrderItem(
            ebookId: Uuid::v7(),
            sellerId: Uuid::v7(),
            title: 'Zen PHP',
            unitPrice: Money::of($priceMinor, Currency::PLN),
        );
    }

    public function testPlaceRecordsOrderPlacedWithFrozenSplit(): void
    {
        $orderId = Uuid::v7();
        $buyerId = Uuid::v7();
        $item = $this->item(2990);

        $order = Order::place($orderId, $buyerId, $item, commissionBps: 1000, withdrawalConsent: true, placedAt: new \DateTimeImmutable('2026-07-22T10:00:00+00:00'));

        self::assertSame(OrderStatus::PENDING, $order->status());
        self::assertTrue($order->aggregateId()->equals($orderId));
        self::assertSame(0, $order->aggregateVersion(), 'A freshly placed order has no persisted events yet.');

        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(OrderPlaced::class, $event);

        // 10% commission on 29,90 zł: author keeps 26,91 zł, platform takes 2,99 zł.
        self::assertSame(2691, $event->authorEarnings->amount);
        self::assertSame(299, $event->platformFee->amount);
        self::assertSame(2990, $event->authorEarnings->amount + $event->platformFee->amount, 'Split must sum back to the price.');
        self::assertSame(1000, $event->commissionBps);
        self::assertTrue($event->buyerId->equals($buyerId));
        self::assertTrue($event->withdrawalConsent);
    }

    public function testReleaseEventsEmptiesTheBuffer(): void
    {
        $order = Order::place(Uuid::v7(), Uuid::v7(), $this->item(), 1000, true, new \DateTimeImmutable());

        self::assertCount(1, $order->releaseEvents());
        self::assertCount(0, $order->releaseEvents(), 'Events are handed off exactly once.');
    }

    public function testReconstituteFromHistoryRebuildsState(): void
    {
        $orderId = Uuid::v7();
        $placed = Order::place($orderId, Uuid::v7(), $this->item(4990), 1000, true, new \DateTimeImmutable());
        $history = $placed->releaseEvents();

        $order = Order::reconstituteFromHistory($history);

        self::assertTrue($order->aggregateId()->equals($orderId));
        self::assertSame(OrderStatus::PENDING, $order->status());
        self::assertSame(4990, $order->total()->amount);
        self::assertSame(1, $order->aggregateVersion(), 'Replaying one event advances the version to 1.');
        self::assertCount(0, $order->releaseEvents(), 'Replayed events are not re-recorded for persistence.');
    }

    public function testFreeEbookCannotBePurchased(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Order::place(Uuid::v7(), Uuid::v7(), $this->item(0), 1000, true, new \DateTimeImmutable());
    }

    public function testCommissionOutOfRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Order::place(Uuid::v7(), Uuid::v7(), $this->item(), commissionBps: 10_001, withdrawalConsent: true, placedAt: new \DateTimeImmutable());
    }

    public function testWithoutConsentOrderIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        Order::place(Uuid::v7(), Uuid::v7(), $this->item(), 1000, withdrawalConsent: false, placedAt: new \DateTimeImmutable());
    }

    /** A freshly placed order with its OrderPlaced event drained from the buffer. */
    private function placedOrder(int $priceMinor = 2990): Order
    {
        $order = Order::place(Uuid::v7(), Uuid::v7(), $this->item($priceMinor), 1000, true, new \DateTimeImmutable());
        $order->releaseEvents();

        return $order;
    }

    public function testInitiatePaymentMovesToAwaiting(): void
    {
        $order = $this->placedOrder();

        $order->initiatePayment('przelewy24', $order->aggregateId()->toRfc4122(), 'TOKEN123', new \DateTimeImmutable());

        self::assertSame(OrderStatus::AWAITING_PAYMENT, $order->status());
        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(PaymentInitiated::class, $events[0]);
    }

    public function testCannotInitiatePaymentTwice(): void
    {
        $order = $this->placedOrder();
        $order->initiatePayment('przelewy24', 's', 't', new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $order->initiatePayment('przelewy24', 's', 't', new \DateTimeImmutable());
    }

    public function testConfirmPaymentMarksPaid(): void
    {
        $order = $this->placedOrder(2990);
        $order->initiatePayment('przelewy24', 's', 't', new \DateTimeImmutable());
        $order->releaseEvents();

        $order->confirmPayment('P24-987', Money::of(2990, Currency::PLN), 'blik', new \DateTimeImmutable());

        self::assertSame(OrderStatus::PAID, $order->status());
        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(PaymentConfirmed::class, $events[0]);
        self::assertSame('P24-987', $events[0]->providerOrderId);
        self::assertSame(2990, $events[0]->paidAmount->amount);
    }

    public function testConfirmPaymentIsIdempotent(): void
    {
        $order = $this->placedOrder(2990);
        $order->initiatePayment('przelewy24', 's', 't', new \DateTimeImmutable());
        $order->confirmPayment('P24-987', Money::of(2990, Currency::PLN), 'blik', new \DateTimeImmutable());
        $order->releaseEvents();

        // A redelivered confirmation must be a no-op — no second event.
        $order->confirmPayment('P24-987', Money::of(2990, Currency::PLN), 'blik', new \DateTimeImmutable());

        self::assertSame(OrderStatus::PAID, $order->status());
        self::assertCount(0, $order->releaseEvents());
    }

    public function testConfirmPaymentRejectsAmountMismatch(): void
    {
        $order = $this->placedOrder(2990);
        $order->initiatePayment('przelewy24', 's', 't', new \DateTimeImmutable());
        $order->releaseEvents();

        $this->expectException(\DomainException::class);
        $order->confirmPayment('P24-987', Money::of(1990, Currency::PLN), 'blik', new \DateTimeImmutable());
    }

    public function testCannotConfirmBeforeInitiating(): void
    {
        $order = $this->placedOrder(2990);

        $this->expectException(\DomainException::class);
        $order->confirmPayment('P24-987', Money::of(2990, Currency::PLN), 'blik', new \DateTimeImmutable());
    }

    public function testFailPaymentMarksFailed(): void
    {
        $order = $this->placedOrder();
        $order->initiatePayment('przelewy24', 's', 't', new \DateTimeImmutable());
        $order->releaseEvents();

        $order->failPayment('cancelled by user', new \DateTimeImmutable());

        self::assertSame(OrderStatus::FAILED, $order->status());
        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(PaymentFailed::class, $events[0]);
    }

    private function paidOrder(): Order
    {
        $order = $this->placedOrder(2990);
        $order->initiatePayment('przelewy24', 's', 't', new \DateTimeImmutable());
        $order->confirmPayment('P24-1', Money::of(2990, Currency::PLN), 'blik', new \DateTimeImmutable());
        $order->releaseEvents();

        return $order;
    }

    public function testFulfilGrantsAccess(): void
    {
        $order = $this->paidOrder();

        $order->fulfill(new \DateTimeImmutable());

        self::assertSame(OrderStatus::FULFILLED, $order->status());
        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderFulfilled::class, $events[0]);
    }

    public function testFulfilIsIdempotent(): void
    {
        $order = $this->paidOrder();
        $order->fulfill(new \DateTimeImmutable());
        $order->releaseEvents();

        $order->fulfill(new \DateTimeImmutable());

        self::assertSame(OrderStatus::FULFILLED, $order->status());
        self::assertCount(0, $order->releaseEvents());
    }

    public function testCannotFulfilBeforePayment(): void
    {
        $order = $this->placedOrder();

        $this->expectException(\DomainException::class);
        $order->fulfill(new \DateTimeImmutable());
    }

    public function testRefundFromPaid(): void
    {
        $order = $this->paidOrder();

        $order->refund('customer request', new \DateTimeImmutable());

        self::assertSame(OrderStatus::REFUNDED, $order->status());
        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderRefunded::class, $events[0]);
        self::assertSame(2990, $events[0]->amount->amount);
    }

    public function testRefundIsIdempotent(): void
    {
        $order = $this->paidOrder();
        $order->refund('r', new \DateTimeImmutable());
        $order->releaseEvents();

        $order->refund('r', new \DateTimeImmutable());

        self::assertSame(OrderStatus::REFUNDED, $order->status());
        self::assertCount(0, $order->releaseEvents());
    }

    public function testCannotRefundUnpaidOrder(): void
    {
        $order = $this->placedOrder();

        $this->expectException(\DomainException::class);
        $order->refund('r', new \DateTimeImmutable());
    }
}
