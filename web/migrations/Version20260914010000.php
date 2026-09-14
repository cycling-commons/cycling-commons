<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Boundaries the project holds, separate from the ones it has onboarded.
 *
 * **Three different lists, and only two existed.** `region` is what the
 * Commons runs: every one has a curator model, a map, a moderation scope.
 * `world_subdivision` is ISO 3166-2 names with no geometry at all. Nothing
 * held the middle: a boundary we have, that nobody is running yet.
 *
 * Without that middle, a rider could only ask for an area by name and the
 * picker had to guess at size from an ISO `type` string, because a name
 * carries no area. With it, size is measured against the calibration band
 * (map-and-search.md §4.5) and onboarding is promoting a row rather than a
 * fresh export.
 *
 * **Its own table, not a flag on `region`.** Every query against `region`
 * today means "a region the Commons runs", and several already carry the
 * filter that keeps country-outline rows out of operational answers
 * (`OperationalRegions::predicate()`). A status column would make every one of
 * them wrong until it was updated, and the one that was missed would put a
 * region nobody curates on the map (owner 2026-09-14: "we can prepare the db
 * in the backend we just not onboard them yet").
 *
 * @see docs/specs/moderation-and-contribution.md §11.1a
 */
final class Version20260914010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'world_division: boundaries available to onboard, held apart from the onboarded ones';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE world_division (
                id BIGSERIAL PRIMARY KEY,
                country_code VARCHAR(2) NOT NULL,
                iso_code VARCHAR(10) DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                subtype VARCHAR(20) NOT NULL,
                geom geometry(Geometry, 4326) NOT NULL,
                area_km2 DOUBLE PRECISION NOT NULL,
                source VARCHAR(40) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
        ');

        // The natural key. Not the ISO code: Overture leaves it null for the
        // uninhabited ones (Coral Sea Islands, Plazas de Soberania), and two
        // nulls do not conflict, so a re-import would duplicate every one of
        // them. Name is what a rider picks from and what a re-import matches.
        $this->addSql('CREATE UNIQUE INDEX uniq_world_division ON world_division (country_code, subtype, name)');
        $this->addSql('CREATE INDEX idx_world_division_geom ON world_division USING gist (geom)');
        // How the picker reads it: one country, biggest first.
        $this->addSql('CREATE INDEX idx_world_division_country ON world_division (country_code, area_km2 DESC)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS world_division');
    }
}
