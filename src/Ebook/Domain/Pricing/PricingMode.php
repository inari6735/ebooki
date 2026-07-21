<?php declare(strict_types=1);

namespace App\Ebook\Domain\Pricing;

/**
 * The three mutually-exclusive ways an eBook can be priced.
 */
enum PricingMode: string
{
    case FREE = 'free';
    case PAY_WHAT_YOU_WANT = 'pay_what_you_want';
    case FIXED = 'fixed';
}
