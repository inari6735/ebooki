<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment\Przelewy24;

/**
 * Validates an incoming Przelewy24 `urlStatus` notification: it must be for our
 * merchant/POS and carry a correct signature (recomputed with the shared CRC and
 * compared in constant time). A notification that fails this is treated as
 * untrusted and never acted upon.
 */
final readonly class P24NotificationVerifier
{
    private const array REQUIRED = [
        'merchantId', 'posId', 'sessionId', 'amount', 'originAmount',
        'currency', 'orderId', 'methodId', 'statement', 'sign',
    ];

    public function __construct(
        private P24Config $config,
        private P24Signer $signer,
    ) {
    }

    /** @param array<string, mixed> $notification */
    public function isValid(array $notification): bool
    {
        foreach (self::REQUIRED as $field) {
            if (!array_key_exists($field, $notification)) {
                return false;
            }
        }

        if ((int) $notification['merchantId'] !== $this->config->merchantId
            || (int) $notification['posId'] !== $this->config->posId) {
            return false;
        }

        $expected = $this->signer->forNotification(
            (int) $notification['merchantId'],
            (int) $notification['posId'],
            (string) $notification['sessionId'],
            (int) $notification['amount'],
            (int) $notification['originAmount'],
            (string) $notification['currency'],
            (int) $notification['orderId'],
            (int) $notification['methodId'],
            (string) $notification['statement'],
        );

        return hash_equals($expected, (string) $notification['sign']);
    }
}
