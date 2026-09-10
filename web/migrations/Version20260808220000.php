<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kilometres or miles, metres or feet (docs/specs/account-and-auth.md §9).
 *
 * Display only. Every distance in the Commons stays metric in the database and
 * in the API — a dataset whose units depend on who is reading it is a dataset
 * nobody can join — and these two columns decide what the last step before the
 * text does with the number.
 *
 * Two columns rather than one "imperial" flag: miles with metres of climbing is
 * a real combination, ridden across Britain, and a single switch would make
 * those riders accept a unit they never use. Existing rows default to metric,
 * which is what everyone was already being shown.
 */
final class Version20260808220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.distance_unit / users.elevation_unit: km-or-mi and m-or-ft, display only';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD distance_unit VARCHAR(8) DEFAULT 'km' NOT NULL");
        $this->addSql("ALTER TABLE users ADD elevation_unit VARCHAR(8) DEFAULT 'm' NOT NULL");
        $this->addSql("COMMENT ON COLUMN users.distance_unit IS 'km|mi — App\\Account\\DistanceUnit. Display only; stored distances stay metric.'");
        $this->addSql("COMMENT ON COLUMN users.elevation_unit IS 'm|ft — App\\Account\\ElevationUnit. Display only; stored heights stay metric.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP distance_unit');
        $this->addSql('ALTER TABLE users DROP elevation_unit');
    }
}
