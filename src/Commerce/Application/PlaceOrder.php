<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\SyncTransport;
use App\Shared\Domain\Money\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Buy now" for a single eBook. Every monetary and commission figure is a
 * server-computed snapshot (never taken from the browser). Handled synchronously
 * because the caller needs the order to exist before redirecting to payment.
 */
final readonly class PlaceOrder implements Command, SyncTransport
{
    public function __construct(
        #[Assert\Uuid]
        public string $orderId,
        #[Assert\Uuid]
        public string $buyerId,
        #[Assert\Uuid]
        public string $ebookId,
        #[Assert\Uuid]
        public string $sellerId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $title,
        #[Assert\Positive]
        public int $unitAmount,
        public Currency $currency,
        #[Assert\Range(min: 0, max: 10_000)]
        public int $commissionBps,
        #[Assert\IsTrue(message: 'Consent to immediate delivery is required.')]
        public bool $withdrawalConsent,
    ) {
    }
}
