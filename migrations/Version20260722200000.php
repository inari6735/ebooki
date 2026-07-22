<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create `media_thumbnails`: a VOLATILE projection of the resized cover variants
 * that live in the media service. Deliberately decoupled from `media` — the only
 * link is media_id (no FK, no cascade), `media` never reads or writes it, and it
 * can be wiped and regenerated at any time. Queried live to build <img srcset>;
 * the composite primary key (media_id, width, format) doubles as the uniqueness
 * guarantee and, being media_id-leading, backs the "IN (…)" lookup.
 */
final class Version20260722200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create media_thumbnails (volatile projection of generated cover variants).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE media_thumbnails (
                media_id UUID NOT NULL,
                width INT NOT NULL,
                height INT NOT NULL,
                format VARCHAR(16) NOT NULL,
                storage_key VARCHAR(1024) NOT NULL,
                size_bytes BIGINT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (media_id, width, format)
            )
            SQL);
        $this->addSql("COMMENT ON COLUMN media_thumbnails.media_id IS '(DC2Type:uuid)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_thumbnails');
    }
}
