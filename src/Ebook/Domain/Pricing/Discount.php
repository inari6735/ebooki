<?php declare(strict_types=1);

namespace App\Ebook\Domain\Pricing;

use App\Shared\Domain\Money\Money;

/**
 * The saving offered by an active promo price: the amount off and the rounded
 * percentage shown as a badge (e.g. -20%).
 */
final readonly class Discount
{
    public function __construct(
        public Money $amount,
        public int $percentage,
    ) {
    }
}
