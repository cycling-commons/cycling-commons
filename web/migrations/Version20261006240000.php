<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * users.privacy_version_seen: the privacy notice version a rider last saw, so a
 * change to the notice is shown to every signed-in rider until they open it.
 *
 * @see docs/specs/privacy-notice.md
 */
final class Version20261006240000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users: privacy_version_seen (null = none yet)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD privacy_version_seen INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP privacy_version_seen');
    }
}
