<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Commerce\Domain\Order\OrderRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class MarkOrderRefundedHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MarkOrderRefunded $command): void
    {
        $order = $this->orders->get(Uuid::fromString($command->orderId));
        $order->refund($command->reason, $this->clock->now());
        $this->orders->save($order);
    }
}
