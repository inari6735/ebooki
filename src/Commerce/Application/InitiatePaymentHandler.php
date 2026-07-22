<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Commerce\Domain\Order\OrderRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class InitiatePaymentHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(InitiatePayment $command): void
    {
        $order = $this->orders->get(Uuid::fromString($command->orderId));
        $order->initiatePayment($command->provider, $command->sessionId, $command->token, $this->clock->now());
        $this->orders->save($order);
    }
}
