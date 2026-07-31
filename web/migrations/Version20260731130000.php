<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A message may reference one of the submission's photos
 * (docs/specs/photo-uploads.md §5b). Deliberately a nullable column on the
 * EXISTING message rather than a second messaging system: the reference is a
 * detail of a normal message. SET NULL on delete, because disposing of a photo
 * must never delete the conversation about it.
 */
final class Version20260731130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'user_message.media_id: optional photo reference on a submission message';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message ADD media_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE user_message ADD CONSTRAINT fk_user_message_media
            FOREIGN KEY (media_id) REFERENCES media_upload (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_user_message_media ON user_message (media_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message DROP CONSTRAINT fk_user_message_media');
        $this->addSql('DROP INDEX idx_user_message_media');
        $this->addSql('ALTER TABLE user_message DROP media_id');
    }
}
