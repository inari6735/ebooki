<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename `p24_notifications` → `payment_notifications`. The raw provider-notification
 * log is provider-agnostic (it has a `provider` column and now also stores PayU),
 * so the P24-specific name was misleading. Data is preserved (pure rename).
 */
final class Version20260722190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename p24_notifications to provider-neutral payment_notifications.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE p24_notifications RENAME TO payment_notifications');
        $this->addSql('ALTER INDEX uniq_p24_notifications_session_order RENAME TO uniq_payment_notifications_session_order');
        $this->addSql('ALTER INDEX idx_p24_notifications_session RENAME TO idx_payment_notifications_session');
        $this->addSql('ALTER INDEX p24_notifications_pkey RENAME TO payment_notifications_pkey');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX payment_notifications_pkey RENAME TO p24_notifications_pkey');
        $this->addSql('ALTER INDEX idx_payment_notifications_session RENAME TO idx_p24_notifications_session');
        $this->addSql('ALTER INDEX uniq_payment_notifications_session_order RENAME TO uniq_p24_notifications_session_order');
        $this->addSql('ALTER TABLE payment_notifications RENAME TO p24_notifications');
    }
}
