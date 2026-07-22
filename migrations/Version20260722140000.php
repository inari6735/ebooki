<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commerce Phase 2 (payments): `commerce_payments` read model (projected from the
 * order payment events) and `p24_notifications` — the append-only raw log of
 * Przelewy24 notifications, whose UNIQUE(session_id, provider_order_id) doubles as
 * the webhook idempotency guard.
 *
 * NOTE for future `doctrine:migrations:diff`: these are not ORM entities
 * (projection + provider log). Keep them; Doctrine may propose dropping them.
 */
final class Version20260722140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commerce payments: commerce_payments read model + p24_notifications raw log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_payments (
                order_id UUID NOT NULL,
                provider VARCHAR(32) NOT NULL,
                session_id VARCHAR(120) NOT NULL,
                provider_order_id VARCHAR(64) DEFAULT NULL,
                method VARCHAR(32) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                initiated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                confirmed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (order_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_commerce_payments_status ON commerce_payments (status)');
        $this->addSql('CREATE INDEX idx_commerce_payments_session ON commerce_payments (session_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE p24_notifications (
                id UUID NOT NULL,
                provider VARCHAR(32) NOT NULL,
                session_id VARCHAR(120) NOT NULL,
                provider_order_id VARCHAR(64) NOT NULL,
                amount INT DEFAULT NULL,
                currency VARCHAR(3) DEFAULT NULL,
                method_id INT DEFAULT NULL,
                signature_valid BOOLEAN NOT NULL,
                status VARCHAR(20) NOT NULL,
                raw_payload JSONB NOT NULL,
                received_at TIMESTAMP(6) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_p24_notifications_session_order ON p24_notifications (session_id, provider_order_id)');
        $this->addSql('CREATE INDEX idx_p24_notifications_session ON p24_notifications (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE p24_notifications');
        $this->addSql('DROP TABLE commerce_payments');
    }
}
