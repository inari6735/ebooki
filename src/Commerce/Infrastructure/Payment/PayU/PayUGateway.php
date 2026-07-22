<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment\PayU;

use App\Commerce\Domain\Payment\Exception\PaymentRefundFailed;
use App\Commerce\Domain\Payment\Exception\PaymentRegistrationFailed;
use App\Commerce\Domain\Payment\Exception\PaymentVerificationFailed;
use App\Commerce\Domain\Payment\PaymentGateway;
use App\Commerce\Domain\Payment\PaymentRegistration;
use App\Commerce\Domain\Payment\PaymentVerification;
use App\Commerce\Domain\Payment\RefundRequest;
use App\Commerce\Domain\Payment\RegisteredPayment;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PayU REST API (v2.1) adapter. OAuth (client_credentials) for a bearer token,
 * then create-order (register), order-status (verify) and refunds. Amounts are
 * always in grosze (as strings, per PayU). All provider/transport failures are
 * converted to domain exceptions so a failure is never mistaken for a payment.
 *
 * @see https://developers.payu.com/ — confirm payloads/fields against current docs.
 */
final readonly class PayUGateway implements PaymentGateway
{
    public const string PROVIDER = 'payu';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PayUConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function register(PaymentRegistration $registration): RegisteredPayment
    {
        $amount = (string) $registration->amount->amount;
        $body = [
            'notifyUrl' => $registration->urlStatus,
            'continueUrl' => $registration->urlReturn,
            'customerIp' => $registration->buyerIp,
            'merchantPosId' => (string) $this->config->posId,
            'description' => $registration->description,
            'currencyCode' => $registration->amount->currency->value,
            'totalAmount' => $amount,
            'extOrderId' => $registration->sessionId,
            'buyer' => ['email' => $registration->buyerEmail, 'language' => $registration->language],
            'products' => [['name' => $registration->description, 'unitPrice' => $amount, 'quantity' => '1']],
        ];

        try {
            $token = $this->authorize();
            // PayU returns 302 with a JSON body (redirectUri); do NOT follow it.
            $response = $this->httpClient->request('POST', $this->config->baseUrl() . '/api/v2_1/orders', [
                'auth_bearer' => $token,
                'json' => $body,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpClientException | \RuntimeException $e) {
            $this->logger->error('PayU order request error', ['sessionId' => $registration->sessionId, 'error' => $e->getMessage()]);
            throw new PaymentRegistrationFailed('PayU order request failed.', 0, $e);
        }

        $orderId = $data['orderId'] ?? null;
        $redirectUri = $data['redirectUri'] ?? null;
        if (!\in_array($status, [200, 201, 302], true)
            || 'SUCCESS' !== ($data['status']['statusCode'] ?? null)
            || !\is_string($orderId) || !\is_string($redirectUri)) {
            $this->logger->error('PayU order rejected', ['sessionId' => $registration->sessionId, 'status' => $status, 'response' => $data]);
            throw new PaymentRegistrationFailed(sprintf('PayU did not create the order (HTTP %d).', $status));
        }

        return new RegisteredPayment($orderId, $redirectUri);
    }

    public function verify(PaymentVerification $verification): void
    {
        try {
            $token = $this->authorize();
            $response = $this->httpClient->request('GET', $this->config->baseUrl() . '/api/v2_1/orders/' . rawurlencode($verification->providerOrderId), [
                'auth_bearer' => $token,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpClientException | \RuntimeException $e) {
            $this->logger->error('PayU verify request error', ['sessionId' => $verification->sessionId, 'error' => $e->getMessage()]);
            throw new PaymentVerificationFailed('PayU verify request failed.', 0, $e);
        }

        $order = $data['orders'][0] ?? [];
        $orderStatus = $order['status'] ?? null;
        $paidAmount = isset($order['totalAmount']) ? (int) $order['totalAmount'] : null;
        if ($status < 200 || $status >= 300 || 'COMPLETED' !== $orderStatus || $paidAmount !== $verification->amount->amount) {
            $this->logger->error('PayU verify rejected', ['sessionId' => $verification->sessionId, 'status' => $status, 'orderStatus' => $orderStatus]);
            throw new PaymentVerificationFailed(sprintf('PayU did not confirm the payment (order status %s).', $orderStatus ?? '?'));
        }
    }

    public function refund(RefundRequest $refund): void
    {
        $body = ['refund' => ['description' => 'Zwrot zamówienia', 'amount' => (string) $refund->amount->amount]];

        try {
            $token = $this->authorize();
            $response = $this->httpClient->request('POST', $this->config->baseUrl() . '/api/v2_1/orders/' . rawurlencode($refund->providerOrderId) . '/refunds', [
                'auth_bearer' => $token,
                'json' => $body,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpClientException | \RuntimeException $e) {
            $this->logger->error('PayU refund request error', ['sessionId' => $refund->sessionId, 'error' => $e->getMessage()]);
            throw new PaymentRefundFailed('PayU refund request failed.', 0, $e);
        }

        if ($status < 200 || $status >= 300 || 'SUCCESS' !== ($data['status']['statusCode'] ?? null)) {
            $this->logger->error('PayU refund rejected', ['sessionId' => $refund->sessionId, 'status' => $status, 'response' => $data]);
            throw new PaymentRefundFailed(sprintf('PayU rejected the refund (HTTP %d).', $status));
        }
    }

    /** OAuth2 client-credentials → bearer access token. */
    private function authorize(): string
    {
        $response = $this->httpClient->request('POST', $this->config->baseUrl() . '/pl/standard/user/oauth/authorize', [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
            ],
        ]);
        $token = $response->toArray(false)['access_token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new \RuntimeException('PayU did not return an access token.');
        }

        return $token;
    }
}
