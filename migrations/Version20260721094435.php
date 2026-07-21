<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ebook context — Phase 1 schema: media, categories, ebooks, ebook_files.
 *
 * NOTE for future `doctrine:migrations:diff` runs: a few objects below are NOT
 * derivable from ORM metadata and must be preserved when reviewing diffs —
 * Doctrine may suggest dropping them:
 *   - cross-context FKs `fk_ebooks_user` / `fk_media_user` (→ users; the owner/
 *     uploader is a soft reference by id, deliberately not an ORM association);
 *   - partial unique index `uniq_ebook_files_primary` (WHERE is_primary);
 *   - the `ck_ebooks_*` CHECK constraints.
 */
final class Version20260721094435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ebook context Phase 1: media, categories, ebooks, ebook_files (+ pricing checks, cross-context FKs).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media (id UUID NOT NULL, disk VARCHAR(32) NOT NULL, path VARCHAR(1024) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(150) NOT NULL, extension VARCHAR(16) NOT NULL, size INT NOT NULL, checksum VARCHAR(64) DEFAULT NULL, visibility VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, user_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_media_checksum ON media (checksum)');
        $this->addSql('CREATE INDEX idx_media_user ON media (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_media_disk_path ON media (disk, path)');

        $this->addSql('CREATE TABLE categories (id UUID NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_categories_parent ON categories (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_categories_slug ON categories (slug)');

        $this->addSql('CREATE TABLE ebooks (id UUID NOT NULL, user_id UUID NOT NULL, title VARCHAR(200) NOT NULL, slug VARCHAR(230) NOT NULL, author_name VARCHAR(100) NOT NULL, language VARCHAR(8) NOT NULL, short_description VARCHAR(150) DEFAULT NULL, description TEXT DEFAULT NULL, attributes JSONB NOT NULL, status VARCHAR(20) NOT NULL, is_free BOOLEAN DEFAULT false NOT NULL, pay_what_you_want BOOLEAN DEFAULT false NOT NULL, price_amount INT DEFAULT NULL, promo_price_amount INT DEFAULT NULL, currency VARCHAR(3) DEFAULT \'PLN\' NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, cover_media_id UUID DEFAULT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ebooks_user ON ebooks (user_id)');
        $this->addSql('CREATE INDEX idx_ebooks_category ON ebooks (category_id)');
        $this->addSql('CREATE INDEX idx_ebooks_cover ON ebooks (cover_media_id)');
        $this->addSql('CREATE INDEX idx_ebooks_status_published ON ebooks (status, published_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ebooks_slug ON ebooks (slug)');

        $this->addSql('CREATE TABLE ebook_files (id UUID NOT NULL, format VARCHAR(16) NOT NULL, role VARCHAR(8) DEFAULT \'full\' NOT NULL, is_primary BOOLEAN DEFAULT false NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ebook_id UUID NOT NULL, media_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ebook_files_ebook ON ebook_files (ebook_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ebook_files_role_format ON ebook_files (ebook_id, role, format)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ebook_files_media ON ebook_files (media_id)');

        // Intra-context foreign keys.
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ebooks ADD CONSTRAINT fk_ebooks_cover_media FOREIGN KEY (cover_media_id) REFERENCES media (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ebooks ADD CONSTRAINT fk_ebooks_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ebook_files ADD CONSTRAINT fk_ebook_files_ebook FOREIGN KEY (ebook_id) REFERENCES ebooks (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ebook_files ADD CONSTRAINT fk_ebook_files_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE RESTRICT NOT DEFERRABLE');

        // Cross-context foreign keys to the User context (soft references by id).
        $this->addSql('ALTER TABLE ebooks ADD CONSTRAINT fk_ebooks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT fk_media_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');

        // At most one primary file per eBook (partial unique index).
        $this->addSql('CREATE UNIQUE INDEX uniq_ebook_files_primary ON ebook_files (ebook_id) WHERE is_primary');

        // Pricing integrity — enforced in the DB, mirroring the domain rules.
        $this->addSql('ALTER TABLE ebooks ADD CONSTRAINT ck_ebooks_pricing_mode_exclusive CHECK (NOT (is_free AND pay_what_you_want))');
        $this->addSql('ALTER TABLE ebooks ADD CONSTRAINT ck_ebooks_price_required CHECK (is_free OR pay_what_you_want OR price_amount IS NOT NULL)');
        $this->addSql('ALTER TABLE ebooks ADD CONSTRAINT ck_ebooks_amounts_non_negative CHECK ((price_amount IS NULL OR price_amount >= 0) AND (promo_price_amount IS NULL OR promo_price_amount >= 0))');
        $this->addSql('ALTER TABLE ebooks ADD CONSTRAINT ck_ebooks_promo_below_price CHECK (promo_price_amount IS NULL OR (price_amount IS NOT NULL AND promo_price_amount < price_amount))');
    }

    public function down(Schema $schema): void
    {
        // Dropping the tables removes their indexes, checks and FK constraints
        // (including the cross-context FKs, which live on ebooks/media).
        $this->addSql('DROP TABLE ebook_files');
        $this->addSql('DROP TABLE ebooks');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE media');
    }
}
