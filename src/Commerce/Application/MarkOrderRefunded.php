<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\SyncTransport;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Records that an order was refunded, after the provider accepted the refund.
 * Handled synchronously so the ledger reversal and entitlement revocation happen
 * atomically with the state change.
 */
final readonly class MarkOrderRefunded implements Command, SyncTransport
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
