<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A climb's point is the foot of its line (owner 2026-09-30: "All climb
 * points must be the start point").
 *
 * A climb (letter N) is a line from foot to summit in `attributes.route`, as
 * [lat, lng] pairs, and `item.geom` is its point. The point is read from the
 * line: `climb_foot(attributes)` is `route[0]` as a point, or NULL when
 * `route` is not an array of at least two entries whose first entry is a
 * numeric pair. A climb with no usable line keeps whatever point it was given.
 *
 * The database keeps it: a BEFORE trigger on item sets `geom` to the foot on
 * every insert of a climb and on every update of a climb's geom, attributes or
 * letter, so every writer (the importers and seeds, the contribution and
 * moderation paths, `app:climbs:recompute`, and tools/wikimedia/climb_line.py,
 * which writes through psql) stores the same point. ClimbFoot::of() is the
 * same rule in PHP, for writers that measure from the point before they write
 * (region, duplicates).
 *
 * A climb's country is the country of the region its foot is in (owner
 * 2026-09-30): the same trigger copies the region's country_code whenever a
 * climb is written with a region, so "Little St Bernard Pass from Morgex",
 * whose foot is in the Aosta Valley, is IT. A climb with no region keeps the
 * country it was given.
 *
 * The backfill moves every climb whose point is not its foot and gives it the
 * region its foot is in, by the membership rule (catalog-data-model.md §6:
 * the smallest region containing the point; none, NULL). A second statement
 * gives every climb with a region that region's country.
 *
 * @see docs/specs/catalog-data-model.md §6
 * @see docs/specs/edit-items/N-climbs.md
 */
final class Version20260930095000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'item: a climb\'s point is the foot of its line, kept by a trigger, and existing climbs moved there';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION climb_foot(a jsonb) RETURNS geometry
            LANGUAGE sql IMMUTABLE AS $$
                SELECT CASE
                    WHEN jsonb_typeof(a->'route') IS DISTINCT FROM 'array' THEN NULL
                    WHEN jsonb_array_length(a->'route') < 2 THEN NULL
                    WHEN jsonb_typeof(a->'route'->0->0) = 'number' AND jsonb_typeof(a->'route'->0->1) = 'number'
                    THEN ST_SetSRID(ST_MakePoint((a->'route'->0->>1)::float8, (a->'route'->0->>0)::float8), 4326)
                END
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION item_climb_at_foot() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE foot geometry := climb_foot(NEW.attributes);
            BEGIN
                IF foot IS NOT NULL THEN
                    NEW.geom := foot;
                END IF;
                IF NEW.region_id IS NOT NULL THEN
                    NEW.country_code := COALESCE((SELECT r.country_code FROM region r WHERE r.id = NEW.region_id AND r.country_code <> ''), NEW.country_code);
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql("CREATE OR REPLACE TRIGGER item_climb_at_foot_ins BEFORE INSERT ON item
            FOR EACH ROW WHEN (NEW.letter = 'N') EXECUTE FUNCTION item_climb_at_foot()");
        $this->addSql("CREATE OR REPLACE TRIGGER item_climb_at_foot_upd BEFORE UPDATE OF geom, attributes, letter, region_id ON item
            FOR EACH ROW WHEN (NEW.letter = 'N') EXECUTE FUNCTION item_climb_at_foot()");
        $this->addSql(<<<'SQL'
            UPDATE item i
               SET geom = f.foot,
                   region_id = (SELECT r.id FROM region r
                                 WHERE ST_Contains(r.geom, f.foot)
                                 ORDER BY r.area_km2 ASC NULLS LAST, r.id ASC
                                 LIMIT 1)
              FROM (SELECT id, climb_foot(attributes) AS foot FROM item WHERE letter = 'N') f
             WHERE i.id = f.id
               AND f.foot IS NOT NULL
               AND NOT ST_Equals(i.geom, f.foot)
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE item i
               SET country_code = r.country_code
              FROM region r
             WHERE i.letter = 'N'
               AND r.id = i.region_id
               AND r.country_code <> ''
               AND i.country_code IS DISTINCT FROM r.country_code
            SQL);
    }

    /** Drops the rule. The points stay at the feet: where each climb's point was before is not kept. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS item_climb_at_foot_ins ON item');
        $this->addSql('DROP TRIGGER IF EXISTS item_climb_at_foot_upd ON item');
        $this->addSql('DROP FUNCTION IF EXISTS item_climb_at_foot()');
        $this->addSql('DROP FUNCTION IF EXISTS climb_foot(jsonb)');
    }
}
