<?php declare(strict_types=1);

namespace App\Tests\Commerce\Application;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Application\ConfirmPaymentFromProviderHandler;
use App\Commerce\Application\InitiatePayment;
use App\Commerce\Application\PlaceOrder;
use App\Commerce\Domain\Payment\Exception\PaymentVerificationFailed;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Tests\Commerce\Doubles\FakePaymentGateway;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ConfirmPaymentTest extends KernelTestCase
{
    private CommandBus $commandBus;
    private Connection $connection;
    private FakePaymentGateway $gateway;
    private ConfirmPaymentFromProviderHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->commandBus = self::getContainer()->get(CommandBus::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->gateway = self::getContainer()->get(FakePaymentGateway::class);
        $this->handler = self::getContainer()->get(ConfirmPaymentFromProviderHandler::class);
    }

    /** @return string orderId of an order that is AWAITING_PAYMENT */
    private function awaitingOrder(int $amount = 2990): string
    {
        $orderId = Uuid::v7()->toRfc4122();
        $this->commandBus->dispatch(new PlaceOrder(
            orderId: $orderId,
            buyerId: Uuid::v7()->toRfc4122(),
            ebookId: Uuid::v7()->toRfc4122(),
            sellerId: Uuid::v7()->toRfc4122(),
            title: 'Zen PHP',
            unitAmount: $amount,
            currency: Currency::PLN,
            commissionBps: 1000,
            withdrawalConsent: true,
        ));
        $this->commandBus->dispatch(new InitiatePayment($orderId, 'przelewy24', $orderId, 'TOKEN'));

        return $orderId;
    }

    public function testVerifiedNotificationMarksOrderPaidAndFulfilled(): void
    {
        $orderId = $this->awaitingOrder(2990);

        ($this->handler)(new ConfirmPaymentFromProvider($orderId, 'P24-555', 2990, Currency::PLN, '25'));

        // Digital good: confirmation fulfils the order in the same step.
        self::assertSame('fulfilled', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
        $payment = $this->connection->fetchAssociative('SELECT status, provider_order_id, method FROM commerce_payments WHERE order_id = ?', [$orderId]);
        self::assertSame('confirmed', $payment['status']);
        self::assertSame('P24-555', $payment['provider_order_id']);
        self::assertCount(1, $this->gateway->verified);
    }

    public function testConfirmationGrantsEntitlementAndPostsBalancedLedger(): void
    {
        $orderId = $this->awaitingOrder(2990);
        $order = $this->connection->fetchAssociative(
            'SELECT buyer_id, ebook_id, seller_id, author_earnings, platform_fee FROM commerce_orders WHERE id = ?',
            [$orderId],
        );

        ($this->handler)(new ConfirmPaymentFromProvider($orderId, 'P24-555', 2990, Currency::PLN, '25'));

        // Entitlement: the buyer now owns the eBook.
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_entitlements WHERE buyer_id = ? AND ebook_id = ?',
            [$order['buyer_id'], $order['ebook_id']],
        ));

        // Ledger: three entries that balance (Σ DR = Σ CR = total).
        $entries = $this->connection->fetchAllAssociative(
            'SELECT account, account_ref, direction, amount FROM commerce_ledger_entries WHERE order_id = ? ORDER BY account',
            [$orderId],
        );
        self::assertCount(3, $entries);

        $debits = array_sum(array_map(static fn ($e): int => 'DR' === $e['direction'] ? (int) $e['amount'] : 0, $entries));
        $credits = array_sum(array_map(static fn ($e): int => 'CR' === $e['direction'] ? (int) $e['amount'] : 0, $entries));
        self::assertSame(2990, $debits);
        self::assertSame($debits, $credits, 'Ledger must balance.');

        $byAccount = [];
        foreach ($entries as $e) {
            $byAccount[$e['account']] = $e;
        }
        self::assertSame((int) $order['author_earnings'], (int) $byAccount['seller_payable']['amount']);
        self::assertSame($order['seller_id'], $byAccount['seller_payable']['account_ref']);
        self::assertSame((int) $order['platform_fee'], (int) $byAccount['platform_income']['amount']);
        self::assertSame(2990, (int) $byAccount['psp_clearing']['amount']);
    }

    public function testLedgerPostingIsIdempotentOnRedelivery(): void
    {
        $orderId = $this->awaitingOrder(2990);
        $command = new ConfirmPaymentFromProvider($orderId, 'P24-555', 2990, Currency::PLN, '25');

        ($this->handler)($command);
        // Re-publish the same PaymentConfirmed projection path via a fresh confirm attempt;
        // the aggregate short-circuits, so no second posting occurs.
        ($this->handler)($command);

        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commerce_ledger_entries WHERE order_id = ?',
            [$orderId],
        ));
    }

    public function testAmountMismatchDoesNotConfirm(): void
    {
        $orderId = $this->awaitingOrder(2990);

        $this->expectException(\DomainException::class);
        try {
            ($this->handler)(new ConfirmPaymentFromProvider($orderId, 'P24-555', 1990, Currency::PLN, '25'));
        } finally {
            self::assertSame('awaiting_payment', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
            self::assertCount(0, $this->gateway->verified, 'A mismatch must be caught before calling the provider.');
        }
    }

    public function testProviderVerifyFailureLeavesOrderUnpaid(): void
    {
        $orderId = $this->awaitingOrder(2990);
        $this->gateway->verifyShouldFail = true;

        try {
            ($this->handler)(new ConfirmPaymentFromProvider($orderId, 'P24-555', 2990, Currency::PLN, '25'));
            self::fail('Expected PaymentVerificationFailed.');
        } catch (PaymentVerificationFailed) {
            self::assertSame('awaiting_payment', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
        }
    }

    public function testConfirmationIsIdempotent(): void
    {
        $orderId = $this->awaitingOrder(2990);
        $command = new ConfirmPaymentFromProvider($orderId, 'P24-555', 2990, Currency::PLN, '25');

        ($this->handler)($command);
        ($this->handler)($command); // redelivery

        self::assertSame('fulfilled', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
        self::assertCount(1, $this->gateway->verified, 'A settled order short-circuits without re-verifying.');
    }
}
