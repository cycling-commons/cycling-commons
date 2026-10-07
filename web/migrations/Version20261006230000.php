<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The rider's time zone: the one they chose (null is automatic) and the one
 * their browser last reported. Times on signed-in pages are written in it.
 *
 * @see docs/specs/account-and-auth.md §9
 */
final class Version20261006230000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users: time_zone (chosen, null = automatic) and detected_time_zone (from the browser)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD time_zone VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD detected_time_zone VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP time_zone');
        $this->addSql('ALTER TABLE users DROP detected_time_zone');
    }
}
