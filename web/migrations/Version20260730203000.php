<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Optional "where can we find you online" link on curator applications
 * (moderation-and-contribution.md, curator-application form). Server-side
 * validation restricts it to a normalized http(s) URL ≤ 255 chars; it is
 * shown to reviewers only, never publicly.
 */
final class Version20260730203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add curator_application.social_url';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curator_application ADD social_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curator_application DROP social_url');
    }
}
