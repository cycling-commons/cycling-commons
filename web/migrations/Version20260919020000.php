<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A room picture uploads before its post exists (the composer shows real
 * upload progress, owner 2026-09-19), so it is held without a post until the
 * post claims it. `uploader_id` says who may claim it; an unclaimed picture is
 * swept by `app:media:gc` after a day.
 *
 * @see docs/specs/moderation-and-contribution.md §13.3
 */
final class Version20260919020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'curator_post_image.post_id nullable + uploader_id (pictures upload before the post claims them)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curator_post_image ALTER post_id DROP NOT NULL');
        $this->addSql('ALTER TABLE curator_post_image ADD uploader_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE curator_post_image ADD CONSTRAINT fk_curator_post_image_uploader FOREIGN KEY (uploader_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_curator_post_image_unclaimed ON curator_post_image (created_at) WHERE post_id IS NULL');
        $this->addSql("COMMENT ON COLUMN curator_post_image.uploader_id IS 'Who uploaded it; only they may attach it to a post. NULL after the account goes.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_curator_post_image_unclaimed');
        $this->addSql('ALTER TABLE curator_post_image DROP CONSTRAINT fk_curator_post_image_uploader');
        $this->addSql('ALTER TABLE curator_post_image DROP uploader_id');
        $this->addSql('ALTER TABLE curator_post_image ALTER post_id SET NOT NULL');
    }
}
