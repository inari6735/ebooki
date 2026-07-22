<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment\Przelewy24;

/**
 * Przelewy24 merchant configuration. Non-secret values may live in committed
 * .env defaults; the CRC and API key are secrets and belong ONLY in
 * .env.local / .env.$APP_ENV.local (never committed). The base URL is derived
 * from the sandbox flag so the same code targets test and production.
 */
final readonly class P24Config
{
    private const string SANDBOX_BASE = 'https://sandbox.przelewy24.pl';
    private const string PRODUCTION_BASE = 'https://secure.przelewy24.pl';

    public function __construct(
        public int $merchantId,
        public int $posId,
        public string $apiKey,
        public bool $sandbox = true,
    ) {
    }

    public function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
    }

    public function redirectUrl(string $token): string
    {
        return $this->baseUrl() . '/trnRequest/' . $token;
    }
}
