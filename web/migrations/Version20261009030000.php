<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Telling people when a legal page changes (docs/specs/privacy-notice.md,
 * docs/specs/translations.md §6.2): the terms version an account last saw, as
 * the privacy notice already has, and one row per account and announced
 * version that was emailed, so an interrupted announcement continues where it
 * stopped and nobody is mailed twice.
 */
final class Version20261009030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.terms_version_seen; legal_notice_sent';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD terms_version_seen INT DEFAULT NULL');
        $this->addSql('CREATE TABLE legal_notice_sent (page VARCHAR(16) NOT NULL, version INT NOT NULL, user_id INT NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (page, version, user_id))');
        $this->addSql('ALTER TABLE legal_notice_sent ADD CONSTRAINT fk_legal_notice_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE legal_notice_sent');
        $this->addSql('ALTER TABLE users DROP terms_version_seen');
    }
}
