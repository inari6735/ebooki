<?php declare(strict_types=1);

namespace App\Tests\Commerce\Doubles;

use App\Commerce\Domain\Payment\Exception\PaymentRefundFailed;
use App\Commerce\Domain\Payment\Exception\PaymentRegistrationFailed;
use App\Commerce\Domain\Payment\Exception\PaymentVerificationFailed;
use App\Commerce\Domain\Payment\PaymentGateway;
use App\Commerce\Domain\Payment\PaymentRegistration;
use App\Commerce\Domain\Payment\PaymentVerification;
use App\Commerce\Domain\Payment\RefundRequest;
use App\Commerce\Domain\Payment\RegisteredPayment;

/**
 * In-memory {@see PaymentGateway} for tests — never touches the network. Records
 * calls and can be toggled to simulate provider failures.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<PaymentRegistration> */
    public array $registered = [];
    /** @var list<PaymentVerification> */
    public array $verified = [];
    /** @var list<RefundRequest> */
    public array $refunded = [];
    public bool $registerShouldFail = false;
    public bool $verifyShouldFail = false;
    public bool $refundShouldFail = false;

    public function provider(): string
    {
        return 'przelewy24';
    }

    public function register(PaymentRegistration $registration): RegisteredPayment
    {
        if ($this->registerShouldFail) {
            throw new PaymentRegistrationFailed('fake register failure');
        }

        $this->registered[] = $registration;
        $token = 'FAKE-' . substr($registration->sessionId, 0, 8);

        return new RegisteredPayment($token, 'https://sandbox.przelewy24.pl/trnRequest/' . $token);
    }

    public function verify(PaymentVerification $verification): void
    {
        $this->verified[] = $verification;

        if ($this->verifyShouldFail) {
            throw new PaymentVerificationFailed('fake verify failure');
        }
    }

    public function refund(RefundRequest $refund): void
    {
        if ($this->refundShouldFail) {
            throw new PaymentRefundFailed('fake refund failure');
        }

        $this->refunded[] = $refund;
    }
}
