<?php declare(strict_types=1);

namespace App\Tests\Commerce\Infrastructure\Payment\PayU;

use App\Commerce\Domain\Payment\Exception\PaymentVerificationFailed;
use App\Commerce\Domain\Payment\PaymentRegistration;
use App\Commerce\Domain\Payment\PaymentVerification;
use App\Commerce\Domain\Payment\RefundRequest;
use App\Commerce\Infrastructure\Payment\PayU\PayUConfig;
use App\Commerce\Infrastructure\Payment\PayU\PayUGateway;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PayUGatewayTest extends TestCase
{
    private function config(): PayUConfig
    {
        return new PayUConfig(posId: 300746, clientId: '300746', clientSecret: 'secret', secondKey: 'k', sandbox: true);
    }

    private function gateway(callable $handler): PayUGateway
    {
        return new PayUGateway(new MockHttpClient($handler), $this->config(), new NullLogger());
    }

    private function oauth(string $method, string $url): ?MockResponse
    {
        return str_contains($url, 'oauth/authorize')
            ? new MockResponse(json_encode(['access_token' => 'tok-123']))
            : null;
    }

    public function testRegisterCreatesOrderAndReturnsRedirect(): void
    {
        $captured = null;
        $gateway = $this->gateway(function (string $m, string $u, array $o) use (&$captured): MockResponse {
            if ($r = $this->oauth($m, $u)) {
                return $r;
            }
            $captured = $o;

            return new MockResponse(
                json_encode(['status' => ['statusCode' => 'SUCCESS'], 'redirectUri' => 'https://secure.snd.payu.com/pay/abc', 'orderId' => 'PAYU-1']),
                ['http_code' => 302],
            );
        });

        $registered = $gateway->register(new PaymentRegistration(
            sessionId: 'ORDER-1',
            amount: Money::of(2990, Currency::PLN),
            description: 'eBook: Zen PHP',
            buyerEmail: 'buyer@example.com',
            urlReturn: 'https://localhost/platnosc/powrot/ORDER-1',
            urlStatus: 'https://localhost/platnosc/payu/notify',
            buyerIp: '203.0.113.7',
        ));

        self::assertSame('PAYU-1', $registered->token);
        self::assertSame('https://secure.snd.payu.com/pay/abc', $registered->redirectUrl);
        self::assertStringContainsString('"extOrderId":"ORDER-1"', $captured['body']);
        self::assertStringContainsString('"totalAmount":"2990"', $captured['body']);
        self::assertStringContainsString('"customerIp":"203.0.113.7"', $captured['body']);
        self::assertStringContainsString('Bearer tok-123', implode(' ', $captured['headers']));
    }

    public function testVerifySucceedsWhenOrderCompletedAndAmountMatches(): void
    {
        $gateway = $this->gateway(function (string $m, string $u): MockResponse {
            return $this->oauth($m, $u) ?? new MockResponse(json_encode([
                'orders' => [['orderId' => 'PAYU-1', 'status' => 'COMPLETED', 'totalAmount' => '2990', 'currencyCode' => 'PLN']],
                'status' => ['statusCode' => 'SUCCESS'],
            ]));
        });

        $gateway->verify(new PaymentVerification('ORDER-1', 'PAYU-1', Money::of(2990, Currency::PLN)));

        $this->expectNotToPerformAssertions();
    }

    public function testVerifyFailsWhenNotCompleted(): void
    {
        $gateway = $this->gateway(fn (string $m, string $u): MockResponse => $this->oauth($m, $u) ?? new MockResponse(json_encode([
            'orders' => [['orderId' => 'PAYU-1', 'status' => 'PENDING', 'totalAmount' => '2990']],
            'status' => ['statusCode' => 'SUCCESS'],
        ])));

        $this->expectException(PaymentVerificationFailed::class);
        $gateway->verify(new PaymentVerification('ORDER-1', 'PAYU-1', Money::of(2990, Currency::PLN)));
    }

    public function testVerifyFailsOnAmountMismatch(): void
    {
        $gateway = $this->gateway(fn (string $m, string $u): MockResponse => $this->oauth($m, $u) ?? new MockResponse(json_encode([
            'orders' => [['orderId' => 'PAYU-1', 'status' => 'COMPLETED', 'totalAmount' => '1990']],
            'status' => ['statusCode' => 'SUCCESS'],
        ])));

        $this->expectException(PaymentVerificationFailed::class);
        $gateway->verify(new PaymentVerification('ORDER-1', 'PAYU-1', Money::of(2990, Currency::PLN)));
    }

    public function testRefundSucceeds(): void
    {
        $captured = null;
        $gateway = $this->gateway(function (string $m, string $u, array $o) use (&$captured): MockResponse {
            if ($r = $this->oauth($m, $u)) {
                return $r;
            }
            $captured = ['url' => $u, 'body' => $o['body']];

            return new MockResponse(json_encode(['status' => ['statusCode' => 'SUCCESS'], 'refund' => ['refundId' => 'R1']]));
        });

        $gateway->refund(new RefundRequest('ORDER-1', 'PAYU-1', Money::of(2990, Currency::PLN)));

        self::assertStringEndsWith('/api/v2_1/orders/PAYU-1/refunds', $captured['url']);
        self::assertStringContainsString('"amount":"2990"', $captured['body']);
    }
}
