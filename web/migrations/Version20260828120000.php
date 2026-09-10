<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photo descriptions, for the people a photo is currently invisible to.
 *
 * Nullable, because it is optional at upload time and most rows will never have
 * one. The render side falls back to the item's name rather than to an empty
 * `alt`, on the owner's call: "Zuiderdijk" read aloud beside a climb is worth
 * more than silence, and unlike a filename it is real information.
 *
 * @see docs/specs/photo-uploads.md §5e
 */
final class Version20260828120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload.alt_text: what somebody who cannot see the photo needs to know.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD alt_text TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload DROP alt_text');
    }
}
