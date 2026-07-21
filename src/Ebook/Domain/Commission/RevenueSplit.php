<?php declare(strict_types=1);

namespace App\Ebook\Domain\Commission;

use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

/**
 * The result of applying a commission policy to a sale: how a paid amount is
 * divided between the author and the platform. It is a plain, immutable result —
 * it does not decide anything (policies do). Uses {@see Money::allocate()} so the
 * parts always sum back to exactly the amount paid; any remainder favours the author.
 */
final readonly class RevenueSplit
{
    private function __construct(
        public Money $authorEarnings,
        public Money $platformFee,
    ) {
    }

    /** Split by a percentage commission (the common case). */
    public static function byShare(Money $paid, CommissionRate $commission): self
    {
        if ($paid->isNegative()) {
            throw new InvalidArgumentException('Cannot split a negative amount.');
        }

        [$author, $platform] = $paid->allocate(
            CommissionRate::FULL_BPS - $commission->basisPoints,
            $commission->basisPoints,
        );

        return new self($author, $platform);
    }

    /**
     * Build the split from already-computed parts. For policies that are not a
     * plain percentage (fixed fee + %, capped fees, processor fees deducted, …).
     */
    public static function fromParts(Money $authorEarnings, Money $platformFee): self
    {
        if ($authorEarnings->currency !== $platformFee->currency) {
            throw new InvalidArgumentException('Author and platform amounts must share a currency.');
        }

        return new self($authorEarnings, $platformFee);
    }

    public function total(): Money
    {
        return $this->authorEarnings->add($this->platformFee);
    }
}
