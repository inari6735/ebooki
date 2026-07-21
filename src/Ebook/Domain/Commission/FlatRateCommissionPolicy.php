<?php declare(strict_types=1);

namespace App\Ebook\Domain\Commission;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Default commission: one flat platform rate for every sale. It always applies,
 * so it must be the LAST policy consulted — the lowest-priority fallback. The
 * rate comes from configuration (see services.yaml), never from the eBook row.
 */
#[AsTaggedItem(priority: -1000)]
final readonly class FlatRateCommissionPolicy implements CommissionPolicy
{
    public function __construct(private CommissionRate $rate)
    {
    }

    public function supports(SaleContext $sale): bool
    {
        return true;
    }

    public function split(SaleContext $sale): RevenueSplit
    {
        return RevenueSplit::byShare($sale->amountPaid, $this->rate);
    }
}
