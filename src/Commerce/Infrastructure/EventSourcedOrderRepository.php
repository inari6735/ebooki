<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\Order\Order;
use App\Commerce\Domain\Order\OrderRepository;
use App\Shared\Infrastructure\EventSourcing\EventSourcedAggregateRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Order persistence via the generic event-sourcing brick — no bespoke storage
 * code, just the stream name and aggregate class.
 *
 * @extends EventSourcedAggregateRepository<Order>
 */
final class EventSourcedOrderRepository extends EventSourcedAggregateRepository implements OrderRepository
{
    public function save(Order $order): void
    {
        $this->persist($order);
    }

    public function get(Uuid $id): Order
    {
        return $this->load($id);
    }

    public function find(Uuid $id): ?Order
    {
        return parent::find($id);
    }

    protected function aggregateType(): string
    {
        return 'commerce.order';
    }

    protected function aggregateClass(): string
    {
        return Order::class;
    }
}
