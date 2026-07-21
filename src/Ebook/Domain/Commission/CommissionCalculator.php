<?php declare(strict_types=1);

namespace App\Ebook\Domain\Commission;

use LogicException;

/**
 * Resolves the applicable {@see CommissionPolicy} for a sale and returns the
 * split. Policies are injected highest-priority first; the first one that
 * `supports()` the sale wins. The default (flat-rate) policy sits at the lowest
 * priority and always matches, so this never falls through.
 */
final readonly class CommissionCalculator
{
    /** @param iterable<CommissionPolicy> $policies */
    public function __construct(private iterable $policies)
    {
    }

    public function calculate(SaleContext $sale): RevenueSplit
    {
        foreach ($this->policies as $policy) {
            if ($policy->supports($sale)) {
                return $policy->split($sale);
            }
        }

        throw new LogicException('No commission policy matched the sale; a default policy must always match.');
    }
}
