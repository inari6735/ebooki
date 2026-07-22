<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment;

use App\Shared\Domain\Money\Money;

/**
 * The facts the gateway must confirm against the provider before an order is
 * treated as paid: our session id, the provider's order id, and the amount we
 * expect (computed server-side from the order, never from the notification).
 */
final readonly class PaymentVerification
{
    public function __construct(
        public string $sessionId,
        public string $providerOrderId,
        public Money $amount,
    ) {
    }
}
