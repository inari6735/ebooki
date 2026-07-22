<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment\Przelewy24;

/**
 * Computes Przelewy24 request/notification signatures. P24 signs a SHA-384 hash
 * over a JSON object of an EXACT, ORDERED set of fields plus the shared CRC key,
 * encoded WITHOUT escaping slashes/unicode. Field order and types (amounts and
 * ids are integers) are part of the contract — getting them wrong yields a wrong
 * signature and rejected transactions, so each signature has its own explicit
 * method and is covered by tests.
 *
 * @see https://developers.przelewy24.pl/ — confirm field sets against current docs.
 */
final readonly class P24Signer
{
    public function __construct(private string $crc)
    {
    }

    /** Sign for POST /transaction/register. */
    public function forRegister(string $sessionId, int $merchantId, int $amount, string $currency): string
    {
        return $this->hash([
            'sessionId' => $sessionId,
            'merchantId' => $merchantId,
            'amount' => $amount,
            'currency' => $currency,
            'crc' => $this->crc,
        ]);
    }

    /** Sign for POST /transaction/verify. */
    public function forVerify(string $sessionId, int $providerOrderId, int $amount, string $currency): string
    {
        return $this->hash([
            'sessionId' => $sessionId,
            'orderId' => $providerOrderId,
            'amount' => $amount,
            'currency' => $currency,
            'crc' => $this->crc,
        ]);
    }

    /**
     * Recompute the signature P24 sends on the `urlStatus` notification, to be
     * compared (constant-time) against the received `sign`.
     */
    public function forNotification(
        int $merchantId,
        int $posId,
        string $sessionId,
        int $amount,
        int $originAmount,
        string $currency,
        int $providerOrderId,
        int $methodId,
        string $statement,
    ): string {
        return $this->hash([
            'merchantId' => $merchantId,
            'posId' => $posId,
            'sessionId' => $sessionId,
            'amount' => $amount,
            'originAmount' => $originAmount,
            'currency' => $currency,
            'orderId' => $providerOrderId,
            'methodId' => $methodId,
            'statement' => $statement,
            'crc' => $this->crc,
        ]);
    }

    /** @param array<string, mixed> $params */
    private function hash(array $params): string
    {
        return hash('sha384', json_encode($params, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }
}
