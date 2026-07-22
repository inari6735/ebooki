<?php declare(strict_types=1);

namespace App\Tests\Commerce\Application;

use App\Commerce\Application\PlaceOrder;
use App\Commerce\Domain\Order\Event\OrderPlaced;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * End-to-end proof of the event-sourcing brick: dispatching PlaceOrder appends an
 * OrderPlaced event AND (because that event is SyncTransport) builds the
 * commerce_orders read model within the same command transaction.
 */
final class PlaceOrderTest extends KernelTestCase
{
    private CommandBus $commandBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->commandBus = self::getContainer()->get(CommandBus::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testPlacingAnOrderStoresEventAndProjectsReadModel(): void
    {
        $orderId = Uuid::v7();
        $buyerId = Uuid::v7();

        $this->commandBus->dispatch(new PlaceOrder(
            orderId: $orderId->toRfc4122(),
            buyerId: $buyerId->toRfc4122(),
            ebookId: Uuid::v7()->toRfc4122(),
            sellerId: Uuid::v7()->toRfc4122(),
            title: 'Zen PHP',
            unitAmount: 2990,
            currency: Currency::PLN,
            commissionBps: 1000,
            withdrawalConsent: true,
        ));

        // 1) The event landed in the append-only store at version 1.
        $stored = $this->connection->fetchAssociative(
            'SELECT event_type, version FROM event_store WHERE aggregate_id = ?',
            [$orderId->toRfc4122()],
        );
        self::assertNotFalse($stored);
        self::assertSame(OrderPlaced::class, $stored['event_type']);
        self::assertSame(1, (int) $stored['version']);

        // 2) The synchronous projection built the read model row atomically.
        $order = $this->connection->fetchAssociative(
            'SELECT * FROM commerce_orders WHERE id = ?',
            [$orderId->toRfc4122()],
        );
        self::assertNotFalse($order);
        self::assertSame($buyerId->toRfc4122(), $order['buyer_id']);
        self::assertSame('pending', $order['status']);
        self::assertSame(2990, (int) $order['total_amount']);
        self::assertSame(2691, (int) $order['author_earnings']);
        self::assertSame(299, (int) $order['platform_fee']);
    }
}
