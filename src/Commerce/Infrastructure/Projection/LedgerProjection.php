<?php declare(strict_types=1);

namespace App\Commerce\Infrastructure\Projection;

use App\Commerce\Domain\Order\Event\PaymentConfirmed;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only double-entry ledger — the auditable money trail. On
 * {@see PaymentConfirmed} it posts a balanced set of entries (Σ debits = Σ
 * credits) recording where the buyer's money is owed: to the author
 * (seller_payable) and to the platform (platform_income), against the amount held
 * at the provider (psp_clearing).
 *
 * The order's frozen split (seller/platform amounts) is read from the
 * commerce_orders read model — both are projections of the same order stream, and
 * OrderPlaced was projected long before payment. Postings are idempotent via a
 * deterministic `reference` ("confirm:{orderId}") so a replay/redelivery never
 * double-posts.
 */
final readonly class LedgerProjection
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'messenger.bus.event')]
    public function onPaymentConfirmed(PaymentConfirmed $event): void
    {
        $orderId = $event->orderId->toRfc4122();

        $order = $this->connection->fetchAssociative(
            'SELECT seller_id, currency, total_amount, author_earnings, platform_fee FROM commerce_orders WHERE id = ?',
            [$orderId],
        );
        if (false === $order) {
            throw new \RuntimeException(sprintf('Cannot post ledger entries: order %s is not projected yet.', $orderId));
        }

        $reference = 'confirm:' . $orderId;
        $currency = (string) $order['currency'];
        $occurredAt = $event->occurredAt()->format('Y-m-d H:i:s.uP');

        // Money held at the provider (asset) balances what we owe the author
        // (liability) plus what the platform earned (income).
        $this->post($reference, 'psp_clearing', null, 'DR', (int) $order['total_amount'], $currency, $orderId, $occurredAt);
        $this->post($reference, 'seller_payable', (string) $order['seller_id'], 'CR', (int) $order['author_earnings'], $currency, $orderId, $occurredAt);
        $this->post($reference, 'platform_income', null, 'CR', (int) $order['platform_fee'], $currency, $orderId, $occurredAt);
    }

    private function post(string $reference, string $account, ?string $accountRef, string $direction, int $amount, string $currency, string $orderId, string $occurredAt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO commerce_ledger_entries
                    (id, reference, account, account_ref, direction, amount, currency, order_id, occurred_at)
                VALUES (:id, :reference, :account, :account_ref, :direction, :amount, :currency, :order_id, :occurred_at)
                ON CONFLICT (reference, account, direction) DO NOTHING
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'reference' => $reference,
                'account' => $account,
                'account_ref' => $accountRef,
                'direction' => $direction,
                'amount' => $amount,
                'currency' => $currency,
                'order_id' => $orderId,
                'occurred_at' => $occurredAt,
            ],
            [
                'id' => ParameterType::STRING,
                'reference' => ParameterType::STRING,
                'account' => ParameterType::STRING,
                'account_ref' => ParameterType::STRING,
                'direction' => ParameterType::STRING,
                'amount' => ParameterType::INTEGER,
                'currency' => ParameterType::STRING,
                'order_id' => ParameterType::STRING,
                'occurred_at' => ParameterType::STRING,
            ],
        );
    }
}
