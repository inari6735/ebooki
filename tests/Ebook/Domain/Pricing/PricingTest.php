<?php declare(strict_types=1);

namespace App\Tests\Ebook\Domain\Pricing;

use App\Ebook\Domain\Pricing\Pricing;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    private function pln(int $amount): Money
    {
        return Money::of($amount, Currency::PLN);
    }

    public function testFree(): void
    {
        $p = Pricing::free(Currency::PLN);

        self::assertTrue($p->isFree());
        self::assertTrue($p->currentPrice()?->isZero());
        self::assertTrue($p->regularPrice()?->isZero());
        self::assertFalse($p->hasActivePromo());
        self::assertNull($p->discount());
        self::assertTrue($p->chargeableAmount()->isZero());
        self::assertNull($p->baseAmountMinor());
    }

    public function testPayWhatYouWantChargesTheChosenAmount(): void
    {
        $p = Pricing::payWhatYouWant(Currency::PLN, $this->pln(1500));

        self::assertTrue($p->isPayWhatYouWant());
        self::assertNull($p->currentPrice());              // buyer decides
        self::assertSame(1500, $p->regularPrice()?->amount); // suggested
        self::assertSame(2500, $p->chargeableAmount($this->pln(2500))->amount);
    }

    public function testPayWhatYouWantRequiresAChosenAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pricing::payWhatYouWant(Currency::PLN)->chargeableAmount(null);
    }

    public function testFixedWithoutPromo(): void
    {
        $p = Pricing::fixed($this->pln(2999));

        self::assertTrue($p->isFixed());
        self::assertSame(2999, $p->currentPrice()?->amount);
        self::assertSame(2999, $p->regularPrice()?->amount);
        self::assertFalse($p->hasActivePromo());
        self::assertNull($p->discount());
        self::assertSame(2999, $p->chargeableAmount()->amount);
        self::assertSame(2999, $p->baseAmountMinor());
        self::assertNull($p->promoAmountMinor());
    }

    public function testFixedWithPromoComputesDiscount(): void
    {
        $p = Pricing::fixed($this->pln(4999), $this->pln(3999));

        self::assertTrue($p->hasActivePromo());
        self::assertSame(3999, $p->currentPrice()?->amount);   // promo is what you pay
        self::assertSame(4999, $p->regularPrice()?->amount);   // struck-through base
        self::assertSame(3999, $p->chargeableAmount()->amount);

        $discount = $p->discount();
        self::assertNotNull($discount);
        self::assertSame(1000, $discount->amount->amount);
        self::assertSame(20, $discount->percentage);           // round(1000/4999*100)
    }

    public function testFixedRejectsPromoNotBelowPrice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pricing::fixed($this->pln(2999), $this->pln(2999));
    }

    public function testFixedRejectsNonPositivePrice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pricing::fixed($this->pln(0));
    }

    public function testFixedRejectsPromoInDifferentCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pricing::fixed($this->pln(4999), Money::of(3999, Currency::EUR));
    }
}
