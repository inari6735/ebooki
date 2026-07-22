<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commerce Phase 3 (fulfilment + ledger): `commerce_entitlements` (buyer's
 * permanent ownership of a purchased eBook, projected from OrderFulfilled) and
 * `commerce_ledger_entries` (append-only double-entry money trail posted on
 * PaymentConfirmed).
 *
 * NOTE for future `doctrine:migrations:diff`: projections, not ORM entities.
 * Keep them; Doctrine may propose dropping them.
 */
final class Version20260722170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commerce: commerce_entitlements + commerce_ledger_entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_entitlements (
                id UUID NOT NULL,
                buyer_id UUID NOT NULL,
                ebook_id UUID NOT NULL,
                order_id UUID NOT NULL,
                granted_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_commerce_entitlements_buyer_ebook ON commerce_entitlements (buyer_id, ebook_id)');
        $this->addSql('CREATE INDEX idx_commerce_entitlements_buyer ON commerce_entitlements (buyer_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_ledger_entries (
                id UUID NOT NULL,
                reference VARCHAR(80) NOT NULL,
                account VARCHAR(40) NOT NULL,
                account_ref VARCHAR(80) DEFAULT NULL,
                direction VARCHAR(2) NOT NULL,
                amount INT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                order_id UUID NOT NULL,
                occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        // Deterministic posting reference makes ledger writes idempotent (replay/redelivery safe).
        $this->addSql('CREATE UNIQUE INDEX uniq_commerce_ledger_ref_account_dir ON commerce_ledger_entries (reference, account, direction)');
        $this->addSql('CREATE INDEX idx_commerce_ledger_account ON commerce_ledger_entries (account, account_ref)');
        $this->addSql('CREATE INDEX idx_commerce_ledger_order ON commerce_ledger_entries (order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commerce_ledger_entries');
        $this->addSql('DROP TABLE commerce_entitlements');
    }
}
