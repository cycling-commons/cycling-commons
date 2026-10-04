<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * town_place.from_osm: whether a town's point was read from OpenStreetMap.
 *
 * The point decides which region a town text is filed in, so only a point the
 * server read from OpenStreetMap is trusted. Every existing row holds a point
 * a reader's map sent and starts as false; TownPlaceRepository::locate()
 * replaces it with the element's own point the next time the town is used.
 *
 * @see docs/specs/map-and-search.md §6.5
 */
final class Version20261004140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'town_place: from_osm, so only a point read from OpenStreetMap files a town text';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_place ADD from_osm BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("COMMENT ON TABLE town_place IS 'Where a town lies, one row per OpenStreetMap ref. It decides which region a town text belongs to.'");
        $this->addSql("COMMENT ON COLUMN town_place.from_osm IS 'True when the server read the point from OpenStreetMap. False is a point a reader''s map sent: not trusted, replaced at the next use.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_place DROP from_osm');
        $this->addSql("COMMENT ON TABLE town_place IS 'Where a town lies, one row per OpenStreetMap ref: the point the first town card reader''s map named for it, kept once. It decides which region a town text belongs to.'");
    }
}
