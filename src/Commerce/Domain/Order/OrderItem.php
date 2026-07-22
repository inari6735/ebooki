<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order;

use App\Shared\Domain\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable snapshot of the single purchased eBook, frozen at checkout: the
 * catalog may change later (price, title, even deletion) but an order records
 * what was actually bought, at what price, from which author. MVP is one item
 * per order.
 */
final readonly class OrderItem
{
    public function __construct(
        public Uuid $ebookId,
        public Uuid $sellerId,
        public string $title,
        public Money $unitPrice,
    ) {
    }
}
