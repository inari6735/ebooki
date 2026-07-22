<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Backoffice;

use Doctrine\DBAL\Connection;

/**
 * Local reconciliation: surfaces anomalies detectable from our own data alone
 * (no provider report needed) so a human can investigate. This is the first line
 * of defence; a full reconciliation against the P24 report is a later addition.
 */
final readonly class ReconciliationReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    /** Orders stuck awaiting payment for longer than the threshold (likely abandoned). */
    public function stuckAwaitingPayment(\DateTimeImmutable $olderThan): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, title, total_amount, currency, placed_at FROM commerce_orders WHERE status = 'awaiting_payment' AND placed_at < ? ORDER BY placed_at ASC",
            [$olderThan->format('Y-m-d H:i:sP')],
        );
    }

    /** Orders paid but never fulfilled — access should have been granted. */
    public function paidNotFulfilled(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, title, total_amount, currency, placed_at FROM commerce_orders WHERE status = 'paid' ORDER BY placed_at ASC",
        );
    }

    /** Orders whose ledger does not balance (Σ DR ≠ Σ CR) — must never happen. */
    public function unbalancedLedger(): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT order_id,
                       SUM(CASE WHEN direction = 'DR' THEN amount ELSE 0 END) AS debits,
                       SUM(CASE WHEN direction = 'CR' THEN amount ELSE 0 END) AS credits
                FROM commerce_ledger_entries
                GROUP BY order_id
                HAVING SUM(CASE WHEN direction = 'DR' THEN amount ELSE 0 END)
                     <> SUM(CASE WHEN direction = 'CR' THEN amount ELSE 0 END)
                SQL,
        );
    }

    /** Notifications that failed signature verification — potential tampering/misconfig. */
    public function invalidSignatureNotifications(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT session_id, provider_order_id, received_at FROM p24_notifications WHERE signature_valid = false ORDER BY received_at DESC LIMIT 100',
        );
    }
}
