<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Commerce\Domain\Order\Order;
use App\Commerce\Domain\Order\OrderItem;
use App\Commerce\Domain\Order\OrderRepository;
use App\Shared\Domain\Money\Money;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class PlaceOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PlaceOrder $command): void
    {
        $item = new OrderItem(
            ebookId: Uuid::fromString($command->ebookId),
            sellerId: Uuid::fromString($command->sellerId),
            title: $command->title,
            unitPrice: Money::of($command->unitAmount, $command->currency),
        );

        $order = Order::place(
            orderId: Uuid::fromString($command->orderId),
            buyerId: Uuid::fromString($command->buyerId),
            item: $item,
            commissionBps: $command->commissionBps,
            withdrawalConsent: $command->withdrawalConsent,
            placedAt: $this->clock->now(),
        );

        $this->orders->save($order);
    }
}
