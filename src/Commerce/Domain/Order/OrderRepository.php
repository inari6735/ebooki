<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order;

use App\Shared\Domain\EventSourcing\Exception\AggregateNotFound;
use Symfony\Component\Uid\Uuid;

interface OrderRepository
{
    public function save(Order $order): void;

    /** @throws AggregateNotFound */
    public function get(Uuid $id): Order;

    public function find(Uuid $id): ?Order;
}
