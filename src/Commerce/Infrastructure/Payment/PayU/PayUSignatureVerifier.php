<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment\PayU;

/**
 * Verifies PayU's `OpenPayU-Signature` header on a notification. PayU signs the
 * RAW request body: signature = hash(body + secondKey) with the algorithm named
 * in the header (MD5 by default, or SHA-256). Compared in constant time; a
 * failing notification is untrusted and never acted upon.
 */
final readonly class PayUSignatureVerifier
{
    public function __construct(private PayUConfig $config)
    {
    }

    public function isValid(string $rawBody, ?string $header): bool
    {
        if (null === $header || '' === $header) {
            return false;
        }

        $parts = $this->parseHeader($header);
        $signature = $parts['signature'] ?? null;
        if (null === $signature) {
            return false;
        }

        $expected = match (strtoupper($parts['algorithm'] ?? 'MD5')) {
            'MD5' => md5($rawBody . $this->config->secondKey),
            'SHA-256', 'SHA256' => hash('sha256', $rawBody . $this->config->secondKey),
            default => null,
        };
        if (null === $expected) {
            return false;
        }

        return hash_equals($expected, strtolower($signature));
    }

    /**
     * Parse `sender=checkout;signature=abc;algorithm=MD5;content=DOCUMENT` into a map.
     *
     * @return array<string, string>
     */
    private function parseHeader(string $header): array
    {
        $out = [];
        foreach (explode(';', $header) as $segment) {
            $segment = trim($segment);
            if ('' === $segment || !str_contains($segment, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $segment, 2);
            $out[strtolower(trim($key))] = trim($value);
        }

        return $out;
    }
}
