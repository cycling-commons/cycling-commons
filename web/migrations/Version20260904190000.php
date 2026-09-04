<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A letter is not a kind: which OSM tags count as the same thing.
 *
 * The harvester matched an upstream record to the nearest coverage POI of the
 * same LETTER, which is what data-provider-hierarchy.md §5 said. Measured
 * against the real Dutch register on 2026-09-04, that is too wide: letter B
 * holds 7024 rows in the Netherlands, of which 2744 are
 * `amenity=drinking_water` and the rest are toilets (150), water points (137),
 * cafés (35), fast food (15) and 3910 rows carrying no `amenity` at all. A
 * public tap was being tied to the café across the road, and the attach count
 * came out 4% above the number the spec measured for taps alone.
 *
 * `match_tags` narrows it: a JSON object of OSM key to accepted values, and a
 * coverage POI qualifies only if it carries one of them. NULL keeps the old
 * behaviour, which is right for a dataset whose letter really is its kind.
 *
 * @see docs/specs/data-provider-hierarchy.md §5, §5.1
 */
final class Version20260904190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'data_provider.match_tags: which OSM tags a provider may match against';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_provider ADD match_tags JSONB DEFAULT NULL');

        // The Dutch register is public drinking water. `water_point` is in
        // because OSM uses it for the same street tap often enough that
        // excluding it would insert a second pin beside a mapped one; every
        // other amenity in letter B is a different thing entirely.
        $this->addSql(<<<'SQL'
            UPDATE data_provider
               SET match_tags = '{"amenity": ["drinking_water", "water_point"]}'::jsonb
             WHERE provider_key = 'rivm-drinkwater'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_provider DROP COLUMN match_tags');
    }
}
