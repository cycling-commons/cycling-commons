<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A division is identified by its ISO code, and by its name only without one.
 *
 * The first key was (country, subtype, name), chosen because Overture ships the
 * uninhabited divisions without an ISO code and two nulls never conflict. It
 * was wrong the other way: Malta has two councils called Ir-Rabat, one on each
 * island, and the first full import kept one and silently overwrote the other
 * (2026-09-14, 3,919 exported boundaries became 3,918 rows).
 *
 * COALESCE covers both: the code where there is one, which is unique by
 * definition, and the name where there is not, which is what the uninhabited
 * ones are told apart by.
 */
final class Version20260914020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'world_division is unique on its ISO code, falling back to its name';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_world_division');
        $this->addSql('CREATE UNIQUE INDEX uniq_world_division ON world_division (country_code, subtype, (COALESCE(iso_code, name)))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_world_division');
        $this->addSql('CREATE UNIQUE INDEX uniq_world_division ON world_division (country_code, subtype, name)');
    }
}
