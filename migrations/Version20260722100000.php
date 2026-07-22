<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commerce foundation: the generic append-only `event_store` (system of record
 * for every event-sourced aggregate) and the `commerce_orders` read model
 * projected from the order stream.
 *
 * NOTE for future `doctrine:migrations:diff` runs — these tables are NOT derived
 * from ORM metadata (the event store is DBAL-only; `commerce_orders` is a
 * projection, not an entity). Doctrine may suggest dropping them; keep them.
 */
final class Version20260722100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commerce: append-only event_store + commerce_orders read model.';
    }

    public function up(Schema $schema): void
    {
        // Append-only event store. `id` is the global stream position (ordering);
        // UNIQUE(aggregate_id, version) enforces optimistic concurrency.
        $this->addSql(<<<'SQL'
            CREATE TABLE event_store (
                id BIGSERIAL NOT NULL,
                aggregate_id UUID NOT NULL,
                aggregate_type VARCHAR(120) NOT NULL,
                version INT NOT NULL,
                event_type VARCHAR(255) NOT NULL,
                payload JSONB NOT NULL,
                metadata JSONB DEFAULT '{}' NOT NULL,
                occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                recorded_at TIMESTAMP(6) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_event_store_stream_version ON event_store (aggregate_id, version)');
        $this->addSql('CREATE INDEX idx_event_store_aggregate_type ON event_store (aggregate_type)');
        $this->addSql('CREATE INDEX idx_event_store_event_type ON event_store (event_type)');

        // Read model projected from the order event stream (rebuildable; no FKs).
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_orders (
                id UUID NOT NULL,
                buyer_id UUID NOT NULL,
                ebook_id UUID NOT NULL,
                seller_id UUID NOT NULL,
                title VARCHAR(200) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                total_amount INT NOT NULL,
                author_earnings INT NOT NULL,
                platform_fee INT NOT NULL,
                commission_bps INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                placed_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_commerce_orders_buyer ON commerce_orders (buyer_id)');
        $this->addSql('CREATE INDEX idx_commerce_orders_seller ON commerce_orders (seller_id)');
        $this->addSql('CREATE INDEX idx_commerce_orders_status ON commerce_orders (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commerce_orders');
        $this->addSql('DROP TABLE event_store');
    }
}
