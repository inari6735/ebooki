<?php declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\EventSourcing;

use App\Commerce\Domain\Order\Event\OrderPlaced;
use App\Shared\Domain\EventSourcing\EventStore;
use App\Shared\Domain\EventSourcing\Exception\ConcurrencyConflict;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineEventStoreTest extends KernelTestCase
{
    private EventStore $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->store = self::getContainer()->get(EventStore::class);
    }

    private function orderPlaced(Uuid $orderId): OrderPlaced
    {
        return new OrderPlaced(
            orderId: $orderId,
            buyerId: Uuid::v7(),
            ebookId: Uuid::v7(),
            sellerId: Uuid::v7(),
            title: 'Zen PHP',
            unitPrice: Money::of(2990, Currency::PLN),
            authorEarnings: Money::of(2691, Currency::PLN),
            platformFee: Money::of(299, Currency::PLN),
            commissionBps: 1000,
            withdrawalConsent: true,
            placedAt: new \DateTimeImmutable('2026-07-22T10:00:00.123456+00:00'),
        );
    }

    public function testAppendAndLoadRoundTrip(): void
    {
        $orderId = Uuid::v7();
        $event = $this->orderPlaced($orderId);

        $this->store->append('commerce.order', $orderId, 0, [$event]);

        $history = $this->store->load($orderId);
        self::assertSame(1, $history->version);
        self::assertCount(1, $history->events);

        $loaded = $history->events[0];
        self::assertInstanceOf(OrderPlaced::class, $loaded);
        self::assertTrue($loaded->orderId->equals($orderId));
        self::assertSame(2990, $loaded->unitPrice->amount);
        self::assertSame(2691, $loaded->authorEarnings->amount);
        self::assertSame(299, $loaded->platformFee->amount);
        self::assertSame(Currency::PLN, $loaded->unitPrice->currency);
        self::assertSame(
            '2026-07-22T10:00:00.123456+00:00',
            $loaded->occurredAt()->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testLoadUnknownAggregateReturnsEmptyHistory(): void
    {
        $history = $this->store->load(Uuid::v7());

        self::assertTrue($history->isEmpty());
        self::assertSame(0, $history->version);
    }

    public function testConcurrentAppendAtSameVersionConflicts(): void
    {
        $orderId = Uuid::v7();
        $this->store->append('commerce.order', $orderId, 0, [$this->orderPlaced($orderId)]);

        $this->expectException(ConcurrencyConflict::class);
        // Second writer still thinks the stream is at version 0 → collides.
        $this->store->append('commerce.order', $orderId, 0, [$this->orderPlaced($orderId)]);
    }
}
