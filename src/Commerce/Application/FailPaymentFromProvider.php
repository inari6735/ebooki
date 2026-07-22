<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\AsyncTransport;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dispatched by the provider webhook when a signature-valid notification reports a
 * terminal FAILURE (e.g. PayU CANCELED). Handled asynchronously (uniform with
 * {@see ConfirmPaymentFromProvider}): the webhook returns 200 fast, the worker
 * marks the order failed. No external call is needed — the signed notification is
 * the source of truth for "it did not go through".
 */
final readonly class FailPaymentFromProvider implements Command, AsyncTransport
{
    public function __construct(
        #[Assert\Uuid]
        public string $orderId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public string $reason,
    ) {
    }
}
