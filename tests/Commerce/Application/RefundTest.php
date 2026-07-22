<?php declare(strict_types=1);

namespace App\Tests\Commerce\Application;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Application\ConfirmPaymentFromProviderHandler;
use App\Commerce\Application\InitiatePayment;
use App\Commerce\Application\PlaceOrder;
use App\Commerce\Application\RefundOrder;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Tests\Commerce\Doubles\FakePaymentGateway;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefundTest extends KernelTestCase
{
    private CommandBus $commandBus;
    private Connection $connection;
    private FakePaymentGateway $gateway;
    private RefundOrder $refundOrder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->commandBus = self::getContainer()->get(CommandBus::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->gateway = self::getContainer()->get(FakePaymentGateway::class);
        $this->refundOrder = self::getContainer()->get(RefundOrder::class);
    }

    private function paidOrder(): string
    {
        $orderId = Uuid::v7()->toRfc4122();
        $this->commandBus->dispatch(new PlaceOrder(
            orderId: $orderId,
            buyerId: Uuid::v7()->toRfc4122(),
            ebookId: Uuid::v7()->toRfc4122(),
            sellerId: Uuid::v7()->toRfc4122(),
            title: 'Zen PHP',
            unitAmount: 2990,
            currency: Currency::PLN,
            commissionBps: 1000,
            withdrawalConsent: true,
        ));
        $this->commandBus->dispatch(new InitiatePayment($orderId, 'przelewy24', $orderId, 'TOKEN'));
        self::getContainer()->get(ConfirmPaymentFromProviderHandler::class)(
            new ConfirmPaymentFromProvider($orderId, 'P24-1', 2990, Currency::PLN, '25'),
        );

        return $orderId;
    }

    public function testRefundReversesLedgerRevokesEntitlementAndCallsProvider(): void
    {
        $orderId = $this->paidOrder();
        $order = $this->connection->fetchAssociative('SELECT buyer_id, ebook_id FROM commerce_orders WHERE id = ?', [$orderId]);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_entitlements WHERE buyer_id = ? AND ebook_id = ?',
            [$order['buyer_id'], $order['ebook_id']],
        ));

        ($this->refundOrder)(Uuid::fromString($orderId), 'customer request');

        // Provider was asked to refund.
        self::assertCount(1, $this->gateway->refunded);
        self::assertSame(2990, $this->gateway->refunded[0]->amount->amount);

        // Order refunded, payment refunded, entitlement revoked.
        self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
        self::assertSame('refunded', $this->connection->fetchOne('SELECT status FROM commerce_payments WHERE order_id = ?', [$orderId]));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_entitlements WHERE buyer_id = ? AND ebook_id = ?',
            [$order['buyer_id'], $order['ebook_id']],
        ));

        // Ledger: confirm (3) + reversal (3) = 6 entries; net per direction is zero.
        $entries = $this->connection->fetchAllAssociative('SELECT direction, amount FROM commerce_ledger_entries WHERE order_id = ?', [$orderId]);
        self::assertCount(6, $entries);
        $net = array_sum(array_map(static fn (array $e): int => 'DR' === $e['direction'] ? (int) $e['amount'] : -(int) $e['amount'], $entries));
        self::assertSame(0, $net, 'Confirm and reversal must cancel out.');
    }

    public function testRefundIsIdempotent(): void
    {
        $orderId = $this->paidOrder();

        ($this->refundOrder)(Uuid::fromString($orderId), 'r');
        ($this->refundOrder)(Uuid::fromString($orderId), 'r'); // no-op

        self::assertCount(1, $this->gateway->refunded, 'A refunded order short-circuits without calling the provider again.');
        self::assertSame(6, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_ledger_entries WHERE order_id = ?', [$orderId]));
    }

    public function testCannotRefundUnpaidOrder(): void
    {
        $orderId = Uuid::v7()->toRfc4122();
        $this->commandBus->dispatch(new PlaceOrder(
            orderId: $orderId,
            buyerId: Uuid::v7()->toRfc4122(),
            ebookId: Uuid::v7()->toRfc4122(),
            sellerId: Uuid::v7()->toRfc4122(),
            title: 'Zen PHP',
            unitAmount: 2990,
            currency: Currency::PLN,
            commissionBps: 1000,
            withdrawalConsent: true,
        ));

        $this->expectException(\DomainException::class);
        ($this->refundOrder)(Uuid::fromString($orderId), 'r');
    }
}
