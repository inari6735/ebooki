<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment\Przelewy24;

use App\Commerce\Domain\Payment\Exception\PaymentRefundFailed;
use App\Commerce\Domain\Payment\Exception\PaymentRegistrationFailed;
use App\Commerce\Domain\Payment\Exception\PaymentVerificationFailed;
use App\Commerce\Domain\Payment\PaymentGateway;
use App\Commerce\Domain\Payment\PaymentRegistration;
use App\Commerce\Domain\Payment\PaymentVerification;
use App\Commerce\Domain\Payment\RefundRequest;
use App\Commerce\Domain\Payment\RegisteredPayment;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Przelewy24 REST API (v1) adapter. Registers hosted transactions and verifies
 * them server-to-server. Auth is HTTP Basic (posId : API key); amounts are always
 * in grosze; every request is signed with {@see P24Signer}. All provider/transport
 * failures are converted to domain exceptions so callers treat them uniformly and
 * never mistake a failure for a payment.
 */
final readonly class Przelewy24Gateway implements PaymentGateway
{
    public const string PROVIDER = 'przelewy24';

    public function __construct(
        private HttpClientInterface $httpClient,
        private P24Config $config,
        private P24Signer $signer,
        private LoggerInterface $logger,
    ) {
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function register(PaymentRegistration $registration): RegisteredPayment
    {
        $amountMinor = $registration->amount->amount;
        $currency = $registration->amount->currency->value;

        $body = [
            'merchantId' => $this->config->merchantId,
            'posId' => $this->config->posId,
            'sessionId' => $registration->sessionId,
            'amount' => $amountMinor,
            'currency' => $currency,
            'description' => $registration->description,
            'email' => $registration->buyerEmail,
            'country' => $registration->buyerCountry,
            'language' => $registration->language,
            'urlReturn' => $registration->urlReturn,
            'urlStatus' => $registration->urlStatus,
            'sign' => $this->signer->forRegister($registration->sessionId, $this->config->merchantId, $amountMinor, $currency),
        ];

        try {
            $response = $this->httpClient->request('POST', $this->config->baseUrl() . '/api/v1/transaction/register', [
                'auth_basic' => [(string) $this->config->posId, $this->config->apiKey],
                'json' => $body,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpClientException $e) {
            $this->logger->error('P24 register transport error', ['sessionId' => $registration->sessionId, 'error' => $e->getMessage()]);
            throw new PaymentRegistrationFailed('Przelewy24 register request failed.', 0, $e);
        }

        $token = $data['data']['token'] ?? null;
        if ($status < 200 || $status >= 300 || !is_string($token) || '' === $token) {
            $this->logger->error('P24 register rejected', ['sessionId' => $registration->sessionId, 'status' => $status, 'response' => $data]);
            throw new PaymentRegistrationFailed(sprintf('Przelewy24 did not return a token (HTTP %d).', $status));
        }

        return new RegisteredPayment($token, $this->config->redirectUrl($token));
    }

    public function verify(PaymentVerification $verification): void
    {
        $amountMinor = $verification->amount->amount;
        $currency = $verification->amount->currency->value;
        $providerOrderId = (int) $verification->providerOrderId;

        $body = [
            'merchantId' => $this->config->merchantId,
            'posId' => $this->config->posId,
            'sessionId' => $verification->sessionId,
            'amount' => $amountMinor,
            'currency' => $currency,
            'orderId' => $providerOrderId,
            'sign' => $this->signer->forVerify($verification->sessionId, $providerOrderId, $amountMinor, $currency),
        ];

        try {
            $response = $this->httpClient->request('PUT', $this->config->baseUrl() . '/api/v1/transaction/verify', [
                'auth_basic' => [(string) $this->config->posId, $this->config->apiKey],
                'json' => $body,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpClientException $e) {
            $this->logger->error('P24 verify transport error', ['sessionId' => $verification->sessionId, 'error' => $e->getMessage()]);
            throw new PaymentVerificationFailed('Przelewy24 verify request failed.', 0, $e);
        }

        $verificationStatus = $data['data']['status'] ?? null;
        if ($status < 200 || $status >= 300 || 'success' !== $verificationStatus) {
            $this->logger->error('P24 verify rejected', ['sessionId' => $verification->sessionId, 'status' => $status, 'response' => $data]);
            throw new PaymentVerificationFailed(sprintf('Przelewy24 did not confirm the payment (HTTP %d).', $status));
        }
    }

    public function refund(RefundRequest $refund): void
    {
        // NOTE: confirm the exact refund payload/signature against current P24 docs
        // before going live; refunds are asynchronous on P24's side (the actual
        // money return is later confirmed via urlStatus).
        $body = [
            'requestId' => Uuid::v7()->toRfc4122(),
            'refunds' => [[
                'orderId' => (int) $refund->providerOrderId,
                'sessionId' => $refund->sessionId,
                'amount' => $refund->amount->amount,
            ]],
            'refundsUuid' => Uuid::v7()->toRfc4122(),
        ];

        try {
            $response = $this->httpClient->request('POST', $this->config->baseUrl() . '/api/v1/transaction/refund', [
                'auth_basic' => [(string) $this->config->posId, $this->config->apiKey],
                'json' => $body,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpClientException $e) {
            $this->logger->error('P24 refund transport error', ['sessionId' => $refund->sessionId, 'error' => $e->getMessage()]);
            throw new PaymentRefundFailed('Przelewy24 refund request failed.', 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->error('P24 refund rejected', ['sessionId' => $refund->sessionId, 'status' => $status, 'response' => $data]);
            throw new PaymentRefundFailed(sprintf('Przelewy24 rejected the refund (HTTP %d).', $status));
        }
    }
}
