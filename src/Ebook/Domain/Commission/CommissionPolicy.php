<?php declare(strict_types=1);

namespace App\Ebook\Domain\Commission;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A rule that decides how one sale's payment is split between author and platform.
 *
 * This is the extension point (Open/Closed): a new commission rule — per-seller,
 * per-category, launch promo, tiered volume, … — is a NEW implementation of this
 * interface, automatically registered via the tag below. Existing policies and
 * the {@see CommissionCalculator} are never modified. A default policy must always
 * `supports()` a sale so the calculator can never fall through.
 */
#[AutoconfigureTag('app.ebook.commission_policy')]
interface CommissionPolicy
{
    public function supports(SaleContext $sale): bool;

    public function split(SaleContext $sale): RevenueSplit;
}
