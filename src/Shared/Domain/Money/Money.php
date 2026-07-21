<?php declare(strict_types=1);

namespace App\Shared\Domain\Money;

use InvalidArgumentException;

/**
 * Immutable money value object. The amount is always stored in the currency's
 * MINOR units (e.g. grosze) as an integer, so there is never any floating-point
 * rounding error. All arithmetic guards against mixing currencies.
 */
final readonly class Money
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {
    }

    public static function of(int $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Split the amount into parts proportional to $ratios. Any remainder left by
     * the integer division is handed out one minor unit at a time to the earliest
     * parts, so the parts ALWAYS sum back to exactly the original amount — no
     * grosz is ever lost or invented. This is the safe way to split money.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        if ([] === $ratios) {
            throw new InvalidArgumentException('Cannot allocate without ratios.');
        }

        $total = 0;
        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Ratios must not be negative.');
            }
            $total += $ratio;
        }
        if ($total <= 0) {
            throw new InvalidArgumentException('Sum of ratios must be positive.');
        }

        $shares = [];
        $remainder = $this->amount;
        foreach ($ratios as $ratio) {
            $share = intdiv($this->amount * $ratio, $total);
            $shares[] = $share;
            $remainder -= $share;
        }

        for ($i = 0, $count = count($shares); $remainder > 0; ++$i, --$remainder) {
            $shares[$i % $count] += 1;
        }

        return array_map(fn (int $amount): self => new self($amount, $this->currency), $shares);
    }

    public function isZero(): bool
    {
        return 0 === $this->amount;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    /** Human-readable value, e.g. "29,99 zł". */
    public function format(): string
    {
        $units = $this->currency->minorUnits();
        $decimals = max(0, strlen((string) $units) - 1);
        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);

        $template = sprintf('%%s%%d,%%0%dd %%s', $decimals);

        return sprintf($template, $sign, intdiv($abs, $units), $abs % $units, $this->currency->symbol());
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Currency mismatch: %s vs %s.',
                $this->currency->value,
                $other->currency->value,
            ));
        }
    }
}
