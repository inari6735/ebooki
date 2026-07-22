<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment;

use App\Commerce\Domain\Payment\Exception\PaymentRegistrationFailed;
use App\Commerce\Domain\Payment\Exception\PaymentVerificationFailed;

/**
 * Port to the payment provider (hosted checkout). The concrete adapter
 * (Przelewy24) lives in Infrastructure and is swappable — the domain/application
 * never knows the provider's HTTP shape, only this contract.
 *
 * The provider hosts the payment page, so card data never touches this app
 * (PCI SAQ-A). Confirmation is authoritative only after {@see verify()} against
 * the provider; the browser return URL is UX only.
 */
interface PaymentGateway
{
    /** Stable provider identifier recorded on the order (e.g. "przelewy24"). */
    public function provider(): string;

    /**
     * Register a transaction and obtain the redirect to the hosted payment page.
     *
     * @throws PaymentRegistrationFailed
     */
    public function register(PaymentRegistration $registration): RegisteredPayment;

    /**
     * Confirm with the provider that the transaction identified by the
     * verification data was actually paid for the expected amount. Returns
     * normally on success; throwing means "do NOT treat as paid".
     *
     * @throws PaymentVerificationFailed
     */
    public function verify(PaymentVerification $verification): void;
}
