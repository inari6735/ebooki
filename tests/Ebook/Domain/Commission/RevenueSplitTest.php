<?php declare(strict_types=1);

namespace App\Tests\Ebook\Domain\Commission;

use App\Ebook\Domain\Commission\CommissionRate;
use App\Ebook\Domain\Commission\RevenueSplit;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevenueSplitTest extends TestCase
{
    private function pln(int $amount): Money
    {
        return Money::of($amount, Currency::PLN);
    }

    public function testByShareTakesCommissionAndRemainderFavoursAuthor(): void
    {
        // 30% platform commission on 29,99 zł → author 21,00 / platform 8,99.
        $split = RevenueSplit::byShare($this->pln(2999), CommissionRate::fromPercent(30));

        self::assertSame(2100, $split->authorEarnings->amount);
        self::assertSame(899, $split->platformFee->amount);
        self::assertSame(2999, $split->total()->amount);
    }

    public function testByShareEdges(): void
    {
        $paid = $this->pln(2999);

        $fullCommission = RevenueSplit::byShare($paid, CommissionRate::fromBasisPoints(10_000));
        self::assertSame(0, $fullCommission->authorEarnings->amount);
        self::assertSame(2999, $fullCommission->platformFee->amount);

        $noCommission = RevenueSplit::byShare($paid, CommissionRate::fromBasisPoints(0));
        self::assertSame(2999, $noCommission->authorEarnings->amount);
        self::assertSame(0, $noCommission->platformFee->amount);

        $free = RevenueSplit::byShare(Money::zero(Currency::PLN), CommissionRate::fromPercent(30));
        self::assertTrue($free->authorEarnings->isZero());
        self::assertTrue($free->platformFee->isZero());
    }

    public function testFromParts(): void
    {
        $split = RevenueSplit::fromParts($this->pln(2100), $this->pln(899));

        self::assertSame(2100, $split->authorEarnings->amount);
        self::assertSame(899, $split->platformFee->amount);
        self::assertSame(2999, $split->total()->amount);
    }

    public function testFromPartsRejectsMixedCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RevenueSplit::fromParts($this->pln(2100), Money::of(899, Currency::EUR));
    }

    public function testByShareRejectsNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RevenueSplit::byShare($this->pln(-1), CommissionRate::fromPercent(30));
    }

    /** The parts must always sum back to the amount paid, whatever the rate. */
    #[DataProvider('amountsAndRates')]
    public function testPartsAlwaysSumBackToTheAmountPaid(int $amount, int $bps): void
    {
        $split = RevenueSplit::byShare($this->pln($amount), CommissionRate::fromBasisPoints($bps));

        self::assertSame($amount, $split->total()->amount);
    }

    /** @return iterable<string, array{int, int}> */
    public static function amountsAndRates(): iterable
    {
        foreach ([1, 3, 5, 99, 2999, 12_345, 1_000_000] as $amount) {
            foreach ([0, 1, 250, 1250, 3000, 5000, 9999, 10_000] as $bps) {
                yield "amount {$amount}, {$bps} bps" => [$amount, $bps];
            }
        }
    }
}
