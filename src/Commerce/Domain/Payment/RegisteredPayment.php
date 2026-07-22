<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment;

/**
 * Result of registering a transaction: the provider token and the URL to redirect
 * the buyer to.
 */
final readonly class RegisteredPayment
{
    public function __construct(
        public string $token,
        public string $redirectUrl,
    ) {
    }
}
