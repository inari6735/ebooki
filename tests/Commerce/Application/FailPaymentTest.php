<?php declare(strict_types=1);

namespace App\Tests\Commerce\Application;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Application\ConfirmPaymentFromProviderHandler;
use App\Commerce\Application\FailPaymentFromProvider;
use App\Commerce\Application\FailPaymentFromProviderHandler;
use App\Commerce\Application\InitiatePayment;
use App\Commerce\Application\PlaceOrder;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FailPaymentTest extends KernelTestCase
{
    private CommandBus $commandBus;
    private Connection $connection;
    private FailPaymentFromProviderHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->commandBus = self::getContainer()->get(CommandBus::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->handler = self::getContainer()->get(FailPaymentFromProviderHandler::class);
    }

    private function awaitingOrder(): string
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
        $this->commandBus->dispatch(new InitiatePayment($orderId, 'payu', $orderId, 'TOKEN'));

        return $orderId;
    }

    public function testFailureMarksOrderFailed(): void
    {
        $orderId = $this->awaitingOrder();

        ($this->handler)(new FailPaymentFromProvider($orderId, 'PayU: CANCELED'));

        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
    }

    public function testFailureIsIdempotent(): void
    {
        $orderId = $this->awaitingOrder();
        $command = new FailPaymentFromProvider($orderId, 'PayU: CANCELED');

        ($this->handler)($command);
        ($this->handler)($command); // redelivery — no error, still failed

        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
    }

    public function testDoesNotFailAnAlreadyFulfilledOrder(): void
    {
        $orderId = $this->awaitingOrder();
        // Confirm + fulfil first (FakePaymentGateway verify succeeds).
        self::getContainer()->get(ConfirmPaymentFromProviderHandler::class)(
            new ConfirmPaymentFromProvider($orderId, 'PAYU-1', 2990, Currency::PLN, null),
        );

        // A late CANCELED must NOT revoke a completed payment.
        ($this->handler)(new FailPaymentFromProvider($orderId, 'PayU: CANCELED'));

        self::assertSame('fulfilled', $this->connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
    }
}
