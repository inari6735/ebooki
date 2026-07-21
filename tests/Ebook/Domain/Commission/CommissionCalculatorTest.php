<?php declare(strict_types=1);

namespace App\Tests\Ebook\Domain\Commission;

use App\Ebook\Domain\Commission\CommissionCalculator;
use App\Ebook\Domain\Commission\CommissionPolicy;
use App\Ebook\Domain\Commission\CommissionRate;
use App\Ebook\Domain\Commission\RevenueSplit;
use App\Ebook\Domain\Commission\SaleContext;
use App\Ebook\Domain\Pricing\PricingMode;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CommissionCalculatorTest extends TestCase
{
    private function sale(int $amount = 2999): SaleContext
    {
        return new SaleContext(
            Uuid::v7(),
            Uuid::v7(),
            null,
            Money::of($amount, Currency::PLN),
            PricingMode::FIXED,
            new \DateTimeImmutable(),
        );
    }

    private function policy(bool $supports, ?CommissionRate $rate = null): CommissionPolicy
    {
        return new class($supports, $rate ?? CommissionRate::fromPercent(30)) implements CommissionPolicy {
            public function __construct(private bool $supports, private CommissionRate $rate)
            {
            }

            public function supports(SaleContext $sale): bool
            {
                return $this->supports;
            }

            public function split(SaleContext $sale): RevenueSplit
            {
                return RevenueSplit::byShare($sale->amountPaid, $this->rate);
            }
        };
    }

    public function testUsesTheFirstSupportingPolicy(): void
    {
        $calculator = new CommissionCalculator([
            $this->policy(true, CommissionRate::fromPercent(10)),  // wins
            $this->policy(true, CommissionRate::fromPercent(50)),  // never reached
        ]);

        $split = $calculator->calculate($this->sale(1000));

        self::assertSame(900, $split->authorEarnings->amount); // 10% commission
    }

    public function testSkipsNonSupportingPoliciesUntilTheFallback(): void
    {
        $calculator = new CommissionCalculator([
            $this->policy(false),
            $this->policy(false),
            $this->policy(true, CommissionRate::fromPercent(30)), // fallback
        ]);

        $split = $calculator->calculate($this->sale(2999));

        self::assertSame(2100, $split->authorEarnings->amount);
        self::assertSame(899, $split->platformFee->amount);
    }

    public function testThrowsWhenNoPolicyMatches(): void
    {
        $calculator = new CommissionCalculator([$this->policy(false)]);

        $this->expectException(LogicException::class);
        $calculator->calculate($this->sale());
    }
}
