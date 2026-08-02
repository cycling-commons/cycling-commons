<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether a takedown request actually withheld the photo
 * (docs/specs/photo-uploads.md §6c).
 *
 * It used to be derived from the source and category. Once the site-wide
 * auto-withhold budget can run out, an urgent report may legitimately leave
 * the photo up, so the fact has to be recorded rather than recomputed —
 * otherwise the photo page would hide an image nobody ever detached.
 */
final class Version20260802190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload.takedown_withheld: record whether a request withheld the photo';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD takedown_withheld BOOLEAN DEFAULT FALSE NOT NULL');
        // Backfill the old derived rule for requests still awaiting a decision:
        // every uploader request withheld, and so did the urgent category.
        $this->addSql(
            "UPDATE media_upload SET takedown_withheld = TRUE
             WHERE takedown_requested_at IS NOT NULL AND objects_deleted_at IS NULL
               AND (takedown_source IS DISTINCT FROM 'third_party' OR takedown_category = 'intimate_or_child')",
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload DROP takedown_withheld');
    }
}
