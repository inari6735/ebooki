<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\Pricing\Pricing;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;

/**
 * Builds the {@see Pricing} value object from the form DTO — the single place that
 * turns the free / pay-what-you-want / fixed(+promo) choice and the major-unit price
 * into domain money, shared by publishing ({@see PublishEbookFromWizard}) and editing
 * ({@see UpdateEbook}) so both price an eBook identically.
 */
final readonly class EbookPricingFactory
{
    public function fromData(PublishEbookData $data): Pricing
    {
        if ($data->isFree) {
            return Pricing::free(Currency::PLN);
        }
        if ($data->payWhatYouWant) {
            return Pricing::payWhatYouWant(
                Currency::PLN,
                null !== $data->price ? $this->money($data->price) : null,
            );
        }

        $price = $this->money($data->price ?? 0.0);
        // Only treat the promo as active when it is a genuine discount.
        $promo = (null !== $data->promoPrice && $data->promoPrice > 0 && $data->promoPrice < ($data->price ?? 0.0))
            ? $this->money($data->promoPrice)
            : null;

        return Pricing::fixed($price, $promo);
    }

    private function money(float $major): Money
    {
        return Money::of((int) round($major * 100), Currency::PLN);
    }
}
