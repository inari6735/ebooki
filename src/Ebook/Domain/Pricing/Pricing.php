<?php declare(strict_types=1);

namespace App\Ebook\Domain\Pricing;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

/**
 * Immutable pricing of an eBook. There are three mutually-exclusive modes — free,
 * pay-what-you-want, and a fixed price (optionally with a promo). All price maths
 * (effective price, discount, amount to charge) lives here so it is defined once
 * and can be unit-tested in isolation from persistence and UI.
 */
final readonly class Pricing
{
    private function __construct(
        private PricingMode $mode,
        private Currency $currency,
        private ?Money $price,       // regular price; null for FREE, optional (suggested) for PWYW
        private ?Money $promoPrice,  // FIXED only; guaranteed 0 <= promo < price
    ) {
    }

    public static function free(Currency $currency): self
    {
        return new self(PricingMode::FREE, $currency, null, null);
    }

    public static function payWhatYouWant(Currency $currency, ?Money $suggested = null): self
    {
        if (null !== $suggested) {
            self::assertCurrency($currency, $suggested);
            if ($suggested->isNegative()) {
                throw new InvalidArgumentException('Suggested amount cannot be negative.');
            }
        }

        return new self(PricingMode::PAY_WHAT_YOU_WANT, $currency, $suggested, null);
    }

    public static function fixed(Money $price, ?Money $promo = null): self
    {
        if (!$price->isPositive()) {
            throw new InvalidArgumentException('A fixed-price eBook must have a positive price.');
        }
        if (null !== $promo) {
            self::assertCurrency($price->currency, $promo);
            if ($promo->isNegative()) {
                throw new InvalidArgumentException('Promo price cannot be negative.');
            }
            if (!$promo->lessThan($price)) {
                throw new InvalidArgumentException('Promo price must be lower than the regular price.');
            }
        }

        return new self(PricingMode::FIXED, $price->currency, $price, $promo);
    }

    public function mode(): PricingMode
    {
        return $this->mode;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function isFree(): bool
    {
        return PricingMode::FREE === $this->mode;
    }

    public function isPayWhatYouWant(): bool
    {
        return PricingMode::PAY_WHAT_YOU_WANT === $this->mode;
    }

    public function isFixed(): bool
    {
        return PricingMode::FIXED === $this->mode;
    }

    /** Regular (pre-promo) price. FREE → zero; PWYW → suggested (may be null); FIXED → price. */
    public function regularPrice(): ?Money
    {
        return match ($this->mode) {
            PricingMode::FREE => Money::zero($this->currency),
            PricingMode::PAY_WHAT_YOU_WANT, PricingMode::FIXED => $this->price,
        };
    }

    /**
     * What a buyer pays right now for a straightforward sale:
     * FREE → zero; FIXED → promo if active, else regular; PWYW → null (buyer decides).
     */
    public function currentPrice(): ?Money
    {
        return match ($this->mode) {
            PricingMode::FREE => Money::zero($this->currency),
            PricingMode::PAY_WHAT_YOU_WANT => null,
            PricingMode::FIXED => $this->promoPrice ?? $this->price,
        };
    }

    public function hasActivePromo(): bool
    {
        return PricingMode::FIXED === $this->mode && null !== $this->promoPrice;
    }

    public function discount(): ?Discount
    {
        if (!$this->hasActivePromo()) {
            return null;
        }

        /** @var Money $price */
        $price = $this->price;
        /** @var Money $promo */
        $promo = $this->promoPrice;

        $off = $price->subtract($promo);
        $percentage = (int) round($off->amount / $price->amount * 100);

        return new Discount($off, $percentage);
    }

    /**
     * The amount to actually charge at checkout.
     * FREE → zero; FIXED → current price (any $chosen is ignored);
     * PWYW → the buyer's chosen amount (required, same currency, non-negative).
     */
    public function chargeableAmount(?Money $chosen = null): Money
    {
        return match ($this->mode) {
            PricingMode::FREE => Money::zero($this->currency),
            PricingMode::FIXED => $this->currentPrice() ?? Money::zero($this->currency),
            PricingMode::PAY_WHAT_YOU_WANT => $this->resolveChosen($chosen),
        };
    }

    public function baseAmountMinor(): ?int
    {
        return $this->price?->amount;
    }

    public function promoAmountMinor(): ?int
    {
        return $this->promoPrice?->amount;
    }

    private function resolveChosen(?Money $chosen): Money
    {
        if (null === $chosen) {
            throw new InvalidArgumentException('Pay-what-you-want requires a chosen amount.');
        }
        self::assertCurrency($this->currency, $chosen);
        if ($chosen->isNegative()) {
            throw new InvalidArgumentException('Chosen amount cannot be negative.');
        }

        return $chosen;
    }

    private static function assertCurrency(Currency $currency, Money $money): void
    {
        if ($money->currency !== $currency) {
            throw new InvalidArgumentException(sprintf(
                'Currency mismatch in pricing: %s vs %s.',
                $currency->value,
                $money->currency->value,
            ));
        }
    }
}
