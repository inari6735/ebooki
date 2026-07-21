<?php declare(strict_types=1);

namespace App\Shared\Domain\Money;

/**
 * Supported currencies. `minorUnits` is the number of minor units in one major
 * unit (100 grosze = 1 zł) and drives how {@see Money} amounts are stored and
 * formatted. Add cases here as new currencies become supported.
 */
enum Currency: string
{
    case PLN = 'PLN';
    case EUR = 'EUR';

    public function minorUnits(): int
    {
        return match ($this) {
            self::PLN, self::EUR => 100,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::PLN => 'zł',
            self::EUR => '€',
        };
    }
}
