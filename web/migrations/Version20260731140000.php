<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The departing rider's choice about the credit on their approved photos
 * (docs/specs/photo-uploads.md §6). Default false — anonymize — because that is
 * what must happen if the rider says nothing.
 */
final class Version20260731140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.keep_media_credit: keep naming me on my photos after deletion, or anonymize';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD keep_media_credit BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("COMMENT ON COLUMN users.keep_media_credit IS 'Delete-account choice (docs/specs/photo-uploads.md §6): keep naming me on my approved photos, or anonymize. Default false = anonymize.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP keep_media_credit');
    }
}
