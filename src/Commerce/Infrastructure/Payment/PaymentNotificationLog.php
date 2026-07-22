<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only log of raw provider notifications — the third audit trail (next to
 * the event store and the ledger). Stores the exact payload plus the signature
 * check result, for reconciliation and debugging. Also the idempotency guard:
 * the UNIQUE(session_id, provider_order_id) index means a redelivered
 * notification is recorded once and {@see record()} reports it as not-new so the
 * caller skips re-processing.
 */
final readonly class PaymentNotificationLog
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $rawPayload
     *
     * @return bool true if this notification was newly recorded, false if it was a duplicate
     */
    public function record(
        string $provider,
        string $sessionId,
        string $providerOrderId,
        ?int $amount,
        ?string $currency,
        ?int $methodId,
        bool $signatureValid,
        array $rawPayload,
    ): bool {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO payment_notifications
                    (id, provider, session_id, provider_order_id, amount, currency,
                     method_id, signature_valid, status, raw_payload, received_at)
                VALUES (:id, :provider, :session_id, :provider_order_id, :amount, :currency,
                        :method_id, :signature_valid, :status, :raw_payload, now())
                ON CONFLICT (session_id, provider_order_id) DO NOTHING
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'provider' => $provider,
                'session_id' => $sessionId,
                'provider_order_id' => $providerOrderId,
                'amount' => $amount,
                'currency' => $currency,
                'method_id' => $methodId,
                'signature_valid' => $signatureValid,
                'status' => $signatureValid ? 'received' : 'rejected',
                'raw_payload' => json_encode($rawPayload, \JSON_THROW_ON_ERROR),
            ],
            [
                'id' => ParameterType::STRING,
                'provider' => ParameterType::STRING,
                'session_id' => ParameterType::STRING,
                'provider_order_id' => ParameterType::STRING,
                'amount' => ParameterType::INTEGER,
                'currency' => ParameterType::STRING,
                'method_id' => ParameterType::INTEGER,
                'signature_valid' => ParameterType::BOOLEAN,
                'status' => ParameterType::STRING,
                'raw_payload' => ParameterType::STRING,
            ],
        );

        return $affected > 0;
    }
}
