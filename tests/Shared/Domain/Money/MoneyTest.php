<?php declare(strict_types=1);

namespace App\Tests\Shared\Domain\Money;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAddAndSubtractSameCurrency(): void
    {
        $a = Money::of(2999, Currency::PLN);
        $b = Money::of(1000, Currency::PLN);

        self::assertSame(3999, $a->add($b)->amount);
        self::assertSame(1999, $a->subtract($b)->amount);
    }

    public function testMultiply(): void
    {
        self::assertSame(6000, Money::of(2000, Currency::PLN)->multiply(3)->amount);
    }

    public function testAddRejectsDifferentCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, Currency::PLN)->add(Money::of(100, Currency::EUR));
    }

    public function testComparingDifferentCurrenciesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, Currency::PLN)->greaterThan(Money::of(100, Currency::EUR));
    }

    public function testComparisonsAndPredicates(): void
    {
        $five = Money::of(500, Currency::PLN);
        $ten = Money::of(1000, Currency::PLN);

        self::assertTrue($ten->greaterThan($five));
        self::assertTrue($five->lessThan($ten));
        self::assertTrue($five->equals(Money::of(500, Currency::PLN)));
        self::assertFalse($five->equals(Money::of(500, Currency::EUR)));
        self::assertTrue(Money::zero(Currency::PLN)->isZero());
        self::assertTrue($five->isPositive());
        self::assertTrue(Money::of(-1, Currency::PLN)->isNegative());
    }

    /**
     * @param list<int> $ratios
     * @param list<int> $expected
     */
    #[DataProvider('allocateProvider')]
    public function testAllocateSplitsExactlyWithoutLosingMinorUnits(int $amount, array $ratios, array $expected): void
    {
        $parts = Money::of($amount, Currency::PLN)->allocate(...$ratios);

        self::assertSame($expected, array_map(static fn (Money $m): int => $m->amount, $parts));
        self::assertSame($amount, array_sum(array_map(static fn (Money $m): int => $m->amount, $parts)));
    }

    /** @return iterable<string, array{int, list<int>, list<int>}> */
    public static function allocateProvider(): iterable
    {
        yield '70/30 of 29,99 → remainder to author' => [2999, [70, 30], [2100, 899]];
        yield 'three equal of 0,10' => [10, [1, 1, 1], [4, 3, 3]];
        yield 'two equal of 0,05' => [5, [1, 1], [3, 2]];
        yield 'zero amount' => [0, [70, 30], [0, 0]];
        yield 'all to first' => [2999, [100, 0], [2999, 0]];
        yield 'all to second' => [2999, [0, 100], [0, 2999]];
    }

    public function testAllocateRejectsBadRatios(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, Currency::PLN)->allocate(0, 0);
    }

    public function testAllocateRejectsNegativeRatio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, Currency::PLN)->allocate(-1, 2);
    }

    #[DataProvider('formatProvider')]
    public function testFormat(int $amount, string $expected): void
    {
        self::assertSame($expected, Money::of($amount, Currency::PLN)->format());
    }

    /** @return iterable<string, array{int, string}> */
    public static function formatProvider(): iterable
    {
        yield '29,99' => [2999, '29,99 zł'];
        yield 'whole thousands' => [100000, '1000,00 zł'];
        yield 'a few grosze' => [5, '0,05 zł'];
        yield 'zero' => [0, '0,00 zł'];
        yield 'negative' => [-2999, '-29,99 zł'];
    }
}
