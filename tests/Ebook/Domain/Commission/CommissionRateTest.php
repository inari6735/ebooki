<?php declare(strict_types=1);

namespace App\Tests\Ebook\Domain\Commission;

use App\Ebook\Domain\Commission\CommissionRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommissionRateTest extends TestCase
{
    public function testFromBasisPoints(): void
    {
        self::assertSame(3000, CommissionRate::fromBasisPoints(3000)->basisPoints);
    }

    public function testFromPercentSupportsFractions(): void
    {
        self::assertSame(1250, CommissionRate::fromPercent(12.5)->basisPoints);
        self::assertSame(30.0, CommissionRate::fromPercent(30)->toPercent());
    }

    #[DataProvider('outOfRange')]
    public function testRejectsOutOfRange(int $bps): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommissionRate::fromBasisPoints($bps);
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRange(): iterable
    {
        yield 'negative' => [-1];
        yield 'over 100%' => [10_001];
    }
}
