<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Backoffice;

use Doctrine\DBAL\Connection;

/**
 * Read-only queries powering the back-office payment views: the payments list,
 * a single order's full audit (event timeline + provider notifications + ledger),
 * and local reconciliation anomalies. Read models only — no writes.
 */
final readonly class PaymentsReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = null): array
    {
        $sql = <<<'SQL'
            SELECT o.id, o.title, o.buyer_id, u.email AS buyer_email, o.total_amount,
                   o.currency, o.status, o.placed_at, p.provider,
                   p.status AS payment_status, p.method, p.provider_order_id
            FROM commerce_orders o
            LEFT JOIN commerce_payments p ON p.order_id = o.id
            LEFT JOIN users u ON u.id = o.buyer_id
            SQL;
        $params = [];
        if (null !== $status && '' !== $status) {
            $sql .= ' WHERE o.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY o.placed_at DESC LIMIT 200';

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function summary(string $orderId): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT o.*, u.email AS buyer_email, p.provider, p.session_id,
                       p.provider_order_id, p.method, p.status AS payment_status,
                       p.initiated_at, p.confirmed_at
                FROM commerce_orders o
                LEFT JOIN commerce_payments p ON p.order_id = o.id
                LEFT JOIN users u ON u.id = o.buyer_id
                WHERE o.id = ?
                SQL,
            [$orderId],
        );

        return false === $row ? null : $row;
    }

    /**
     * The order's full event history from the append-only store — the audit trail.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(string $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT version, event_type, payload, metadata, occurred_at FROM event_store WHERE aggregate_id = ? ORDER BY version ASC',
            [$orderId],
        );

        return array_map(static function (array $row): array {
            $type = (string) $row['event_type'];

            return [
                'version' => (int) $row['version'],
                'event' => substr((string) strrchr($type, '\\'), 1) ?: $type,
                'occurred_at' => $row['occurred_at'],
                'payload' => json_decode((string) $row['payload'], true, 512, \JSON_THROW_ON_ERROR),
                'metadata' => json_decode((string) $row['metadata'], true, 512, \JSON_THROW_ON_ERROR),
            ];
        }, $rows);
    }

    /** @return list<array<string, mixed>> raw provider notifications for this order */
    public function notifications(string $orderId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT provider_order_id, amount, currency, method_id, signature_valid, status, received_at FROM payment_notifications WHERE session_id = ? ORDER BY received_at ASC',
            [$orderId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function ledger(string $orderId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT reference, account, account_ref, direction, amount, currency, occurred_at FROM commerce_ledger_entries WHERE order_id = ? ORDER BY occurred_at ASC, reference ASC',
            [$orderId],
        );
    }
}
