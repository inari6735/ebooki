<?php declare(strict_types=1);

namespace App\Ebook\Domain\Commission;

use App\Ebook\Domain\Pricing\PricingMode;
use App\Shared\Domain\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * An immutable snapshot of the facts about one sale, handed to commission
 * policies so they can decide the split WITHOUT reaching into entities or the
 * database. Not persisted — the concrete split is snapshotted on the order line
 * (Phase 2). `amountPaid` is what the buyer actually paid for this transaction
 * (for pay-what-you-want, the amount they chose at checkout).
 */
final readonly class SaleContext
{
    public function __construct(
        public Uuid $ebookId,
        public Uuid $sellerId,
        public ?Uuid $categoryId,
        public Money $amountPaid,
        public PricingMode $mode,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
