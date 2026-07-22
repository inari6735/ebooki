<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment\PayU;

/**
 * PayU merchant configuration. `clientId`/`clientSecret` are the OAuth
 * credentials, `posId` is the merchant POS id, `secondKey` (the "second key /
 * MD5") signs and verifies notifications. Secrets belong ONLY in .env.local. The
 * base URL is derived from the sandbox flag.
 */
final readonly class PayUConfig
{
    private const string SANDBOX_BASE = 'https://secure.snd.payu.com';
    private const string PRODUCTION_BASE = 'https://secure.payu.com';

    public function __construct(
        public int $posId,
        public string $clientId,
        public string $clientSecret,
        public string $secondKey,
        public bool $sandbox = true,
    ) {
    }

    public function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
    }
}
