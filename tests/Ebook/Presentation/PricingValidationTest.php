<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The step-3 pricing rules, enforced server-side (the price-calc controller applies
 * the identical checks on the frontend): a fixed price must be > 0, and an optional
 * promo price must be ≥ 0 and never higher than the base price.
 */
final class PricingValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testPromoAbovePriceIsRejected(): void
    {
        $data = $this->pricing(price: 30.0, promo: 40.0);

        self::assertMessages(['Cena promocyjna nie może być wyższa od ceny eBooka.'], $this->violations($data));
    }

    public function testPromoEqualToPriceIsAllowed(): void
    {
        self::assertCount(0, $this->violations($this->pricing(price: 30.0, promo: 30.0)));
    }

    public function testPromoBelowPriceIsAllowed(): void
    {
        self::assertCount(0, $this->violations($this->pricing(price: 30.0, promo: 19.99)));
    }

    public function testNoPromoIsAllowed(): void
    {
        self::assertCount(0, $this->violations($this->pricing(price: 30.0, promo: null)));
    }

    public function testZeroPriceIsRejected(): void
    {
        self::assertMessages(['Cena musi być większa od zera.'], $this->violations($this->pricing(price: 0.0, promo: null)));
    }

    public function testNegativePromoIsRejected(): void
    {
        self::assertMessages(['Cena promocyjna nie może być ujemna.'], $this->violations($this->pricing(price: 30.0, promo: -5.0)));
    }

    public function testPromoIgnoredWhenFree(): void
    {
        $data = $this->pricing(price: null, promo: null);
        $data->isFree = true;

        self::assertCount(0, $this->violations($data));
    }

    private function pricing(?float $price, ?float $promo): PublishEbookData
    {
        $data = new PublishEbookData();
        $data->price = $price;
        $data->promoPrice = $promo;

        return $data;
    }

    /** @return list<string> */
    private function violations(PublishEbookData $data): array
    {
        $messages = [];
        foreach ($this->validator->validate($data, null, ['pricing']) as $violation) {
            $messages[] = $violation->getMessage();
        }

        return $messages;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private static function assertMessages(array $expected, array $actual): void
    {
        foreach ($expected as $message) {
            self::assertContains($message, $actual);
        }
    }
}
