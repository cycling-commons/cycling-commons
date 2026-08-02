<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Room for longer action names in the media moderation log.
 *
 * `takedown_dismissed_as_abuse` (docs/specs/photo-uploads.md §6c) is 27
 * characters and the column was 24. Widened to 40 rather than shortened to
 * fit: the log is read by people, and an action that has to be abbreviated to
 * fit its column is a column problem.
 */
final class Version20260802200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_moderation_event.action: widen to 40 characters';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_moderation_event ALTER action TYPE VARCHAR(40)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_moderation_event ALTER action TYPE VARCHAR(24)');
    }
}
