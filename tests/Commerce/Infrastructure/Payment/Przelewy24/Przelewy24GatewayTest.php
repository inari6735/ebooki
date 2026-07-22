<?php declare(strict_types=1);

namespace App\Tests\Commerce\Infrastructure\Payment\Przelewy24;

use App\Commerce\Domain\Payment\Exception\PaymentVerificationFailed;
use App\Commerce\Domain\Payment\PaymentRegistration;
use App\Commerce\Domain\Payment\PaymentVerification;
use App\Commerce\Infrastructure\Payment\Przelewy24\P24Config;
use App\Commerce\Infrastructure\Payment\Przelewy24\P24Signer;
use App\Commerce\Infrastructure\Payment\Przelewy24\Przelewy24Gateway;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Przelewy24GatewayTest extends TestCase
{
    private function config(): P24Config
    {
        return new P24Config(merchantId: 12345, posId: 12345, apiKey: 'secret-api-key', sandbox: true);
    }

    private function registration(): PaymentRegistration
    {
        return new PaymentRegistration(
            sessionId: 'ORDER-1',
            amount: Money::of(2990, Currency::PLN),
            description: 'eBook: Zen PHP',
            buyerEmail: 'buyer@example.com',
            urlReturn: 'https://localhost/platnosc/powrot/ORDER-1',
            urlStatus: 'https://localhost/platnosc/przelewy24/status',
        );
    }

    public function testRegisterReturnsTokenAndRedirectUrlAndSignsRequest(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode(['data' => ['token' => 'TOK-XYZ']]), ['http_code' => 200]);
        });

        $gateway = new Przelewy24Gateway($client, $this->config(), new P24Signer('crc-key'), new NullLogger());
        $registered = $gateway->register($this->registration());

        self::assertSame('TOK-XYZ', $registered->token);
        self::assertSame('https://sandbox.przelewy24.pl/trnRequest/TOK-XYZ', $registered->redirectUrl);

        self::assertNotNull($captured);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/api/v1/transaction/register', $captured['url']);
        self::assertStringContainsString('"sign"', $captured['options']['body']);
        self::assertStringContainsString('"amount":2990', $captured['options']['body']);
        self::assertStringContainsString('Basic ', implode(' ', $captured['options']['headers']));
    }

    public function testVerifySucceedsOnSuccessStatus(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['data' => ['status' => 'success']]), ['http_code' => 200]));
        $gateway = new Przelewy24Gateway($client, $this->config(), new P24Signer('crc-key'), new NullLogger());

        $gateway->verify(new PaymentVerification('ORDER-1', '98765', Money::of(2990, Currency::PLN)));

        $this->expectNotToPerformAssertions();
    }

    public function testVerifyThrowsWhenProviderDoesNotConfirm(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['error' => 'bad']), ['http_code' => 400]));
        $gateway = new Przelewy24Gateway($client, $this->config(), new P24Signer('crc-key'), new NullLogger());

        $this->expectException(PaymentVerificationFailed::class);
        $gateway->verify(new PaymentVerification('ORDER-1', '98765', Money::of(2990, Currency::PLN)));
    }
}
