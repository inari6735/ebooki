<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\SyncTransport;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Records that a payment transaction was registered at the provider for an order.
 * Dispatched synchronously right after the (non-transactional) provider call, so
 * the order reflects "awaiting payment" before the buyer is redirected.
 */
final readonly class InitiatePayment implements Command, SyncTransport
{
    public function __construct(
        #[Assert\Uuid]
        public string $orderId,
        #[Assert\NotBlank]
        public string $provider,
        #[Assert\NotBlank]
        public string $sessionId,
        #[Assert\NotBlank]
        public string $token,
    ) {
    }
}
