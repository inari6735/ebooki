<?php declare(strict_types=1);

namespace App\Tests\Ebook\Domain\Commission;

use App\Ebook\Domain\Commission\CommissionCalculator;
use App\Ebook\Domain\Pricing\PricingMode;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Verifies the container wiring end-to-end: the tagged-iterator of policies, the
 * flat-rate fallback, and the commission rate read from config all connect so
 * that a real sale yields the configured 10% platform / 90% author split.
 */
final class CommissionWiringTest extends KernelTestCase
{
    public function testCalculatorResolvesTheConfiguredDefaultSplit(): void
    {
        self::bootKernel();
        /** @var CommissionCalculator $calculator */
        $calculator = self::getContainer()->get('test.ebook.commission_calculator');

        $split = $calculator->calculate(new \App\Ebook\Domain\Commission\SaleContext(
            Uuid::v7(),
            Uuid::v7(),
            null,
            Money::of(2999, Currency::PLN),
            PricingMode::FIXED,
            new \DateTimeImmutable(),
        ));

        self::assertSame(2700, $split->authorEarnings->amount); // 90% of 29,99 zł
        self::assertSame(299, $split->platformFee->amount);     // 10% platform
    }
}
