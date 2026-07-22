<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\AsyncTransport;
use App\Shared\Domain\Money\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dispatched by the provider webhook after a signature-valid notification.
 * Handled ASYNCHRONOUSLY on the worker: the webhook returns 200 fast, and the
 * heavy work (provider "verify" call + confirming the order) happens off-request
 * with Messenger retries. The amount/currency come from the notification and are
 * cross-checked against the order server-side before anything is confirmed.
 */
final readonly class ConfirmPaymentFromProvider implements Command, AsyncTransport
{
    public function __construct(
        #[Assert\Uuid]
        public string $orderId,
        #[Assert\NotBlank]
        public string $providerOrderId,
        #[Assert\Positive]
        public int $amountMinor,
        public Currency $currency,
        public ?string $method = null,
    ) {
    }
}
