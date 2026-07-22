<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment;

use App\Shared\Domain\Money\Money;

/**
 * Ask the provider to return money for a confirmed transaction (full refund in
 * the MVP). Amount is computed server-side from the order.
 */
final readonly class RefundRequest
{
    public function __construct(
        public string $sessionId,
        public string $providerOrderId,
        public Money $amount,
    ) {
    }
}
