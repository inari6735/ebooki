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

    public function testVerifiedNotificationMarksOrderPaid(): void
    {
        $orderId = $this->awaitingOrder(2990);

        ($this->handler)(new ConfirmPaymentFromProvider($orderId, 'P24-555', 2990, Currency::PLN, '25'));

        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
        $payment = $this->connection->fetchAssociative('SELECT status, provider_order_id, method FROM commerce_payments WHERE order_id = ?', [$orderId]);
        self::assertSame('confirmed', $payment['status']);
        self::assertSame('P24-555', $payment['provider_order_id']);
        self::assertCount(1, $this->gateway->verified);
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

        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
        self::assertCount(1, $this->gateway->verified, 'A paid order short-circuits without re-verifying.');
    }
}
