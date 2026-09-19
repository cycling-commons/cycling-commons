<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Images on curator-room posts, kept in the database and served to curators
 * only, the way bug-report screenshots are.
 *
 * @see docs/specs/moderation-and-contribution.md §13.3
 */
final class Version20260919010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'curator_post_image (images on curator-room posts, in the database, curators only)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE curator_post_image (
            id BIGSERIAL NOT NULL,
            post_id BIGINT NOT NULL,
            position SMALLINT DEFAULT 0 NOT NULL,
            mime_type VARCHAR(40) NOT NULL,
            bytes BYTEA NOT NULL,
            byte_size INT NOT NULL,
            width INT NOT NULL,
            height INT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_curator_post_image_post ON curator_post_image (post_id, position)');
        $this->addSql('ALTER TABLE curator_post_image ADD CONSTRAINT fk_curator_post_image_post FOREIGN KEY (post_id) REFERENCES curator_post (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN curator_post_image.bytes IS 'The image as this server re-encoded it; never the uploaded file. Served to curators only (moderation-and-contribution.md §13.3).'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE curator_post_image');
    }
}
