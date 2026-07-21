<?php declare(strict_types=1);

namespace App\Ebook\Domain\Commission;

use InvalidArgumentException;

/**
 * The platform's commission expressed in basis points (1% = 100 bps), so
 * fractional rates like 12.5% are representable without floats. The author keeps
 * the remainder (FULL_BPS - basisPoints).
 */
final readonly class CommissionRate
{
    public const int FULL_BPS = 10_000;

    private function __construct(public int $basisPoints)
    {
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::FULL_BPS) {
            throw new InvalidArgumentException(sprintf('Commission must be between 0 and %d bps.', self::FULL_BPS));
        }

        return new self($basisPoints);
    }

    public static function fromPercent(int|float $percent): self
    {
        return self::fromBasisPoints((int) round($percent * 100));
    }

    public function toPercent(): float
    {
        return $this->basisPoints / 100;
    }
}
