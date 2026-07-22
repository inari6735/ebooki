<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment;

use App\Shared\Domain\Money\Money;

/**
 * Everything the gateway needs to open a hosted payment for one order.
 * `sessionId` is our correlation id (the order id) echoed back by the provider;
 * `urlStatus` is the server-to-server notification endpoint (source of truth);
 * `urlReturn` is where the buyer's browser comes back (UX only).
 */
final readonly class PaymentRegistration
{
    public function __construct(
        public string $sessionId,
        public Money $amount,
        public string $description,
        public string $buyerEmail,
        public string $urlReturn,
        public string $urlStatus,
        public string $buyerCountry = 'PL',
        public string $language = 'pl',
        public string $buyerIp = '127.0.0.1',
    ) {
    }
}
