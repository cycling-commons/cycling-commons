<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every dataset the credits page names becomes a registry row.
 *
 * `/credits` listed its providers as hand-written rows in one template, in one
 * language, with the sentence about each one as a translated message key.
 * Generating those rows from the registry means adding the eleventh provider
 * is a row rather than an edit in five languages.
 *
 * Two columns carry the sentence, and the renderer prefers the first:
 * `blurb_key` is a message key, so **every existing translation survives
 * untouched**, and `blurb` is free English for a row a curator adds at the
 * desk. The six rows seeded here take the keys the template already uses.
 *
 * `rank` is 0 for a provider that produces no `item` rows. Rank orders
 * authorities when two of them describe one place; a boundary set, an extract
 * mirror or a photo library never does, and they are in the registry to be
 * cited, not to be ranked.
 *
 * The parked rows stay parked: the Nationaal Georegister and Drinkwaterkaart
 * are commented out on the page pending a conversation with their maintainer,
 * and seeding them here would put them back on it.
 *
 * @see docs/specs/data-provider-hierarchy.md §9, §9.2, §9.3
 * @see docs/specs/credits-page.md §8.8
 */
final class Version20260904140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Provider blurbs, and a registry row for every dataset the credits page names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_provider ADD blurb_key VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE data_provider ADD blurb TEXT DEFAULT NULL');

        // The three seeded in Version20260904110000 take the keys the credits
        // template already renders, so no wording changes in any language.
        $this->addSql("UPDATE data_provider SET blurb_key = 'credits.osm_p' WHERE provider_key = 'osm'");
        $this->addSql("UPDATE data_provider SET blurb_key = 'credits.wallonia_p' WHERE provider_key = 'wallonie-pivot'");
        $this->addSql("UPDATE data_provider SET attribution = '© OpenStreetMap contributors' WHERE provider_key = 'osm'");

        $this->addSql(<<<'SQL'
            INSERT INTO data_provider
                (provider_key, name, full_name, homepage, licence, licence_code, rank,
                 letters, attribution, blurb_key, refresh_cadence, system, enabled)
            VALUES
                ('overture', 'Overture Maps Foundation', 'Overture Maps Foundation, divisions theme',
                 'https://overturemaps.org/',
                 'Open Database License (ODbL) 1.0', 'odbl', 0, '[]'::jsonb,
                 '© Overture Maps Foundation · © OpenStreetMap contributors',
                 'credits.overture_p', 'per pinned release', FALSE, TRUE),
                ('geofabrik', 'Geofabrik', 'Geofabrik OpenStreetMap extracts',
                 'https://www.geofabrik.de/',
                 'Open Database License (ODbL) 1.0', 'odbl', 0, '[]'::jsonb,
                 '© OpenStreetMap contributors',
                 'credits.geofabrik_p', 'per harvest', FALSE, TRUE),
                ('wikimedia-commons', 'Wikimedia Commons', 'Wikimedia Commons',
                 'https://commons.wikimedia.org/',
                 'Per-photo licence, shown on each photo', 'per-photo', 0, '[]'::jsonb,
                 NULL,
                 'credits.wikimedia_p', 'resolved at harvest', FALSE, TRUE),
                ('wikipedia', 'Wikipedia', 'Wikipedia',
                 'https://www.wikipedia.org/',
                 'Creative Commons BY-SA 4.0', 'cc-by-sa-4.0', 0, '[]'::jsonb,
                 NULL,
                 'credits.wikipedia_p', 'at build time', FALSE, TRUE),
                ('copernicus-worlddem30', 'Copernicus WorldDEM-30', 'Copernicus WorldDEM-30',
                 'https://dataspace.copernicus.eu/explore-data/data-collections/copernicus-contributing-missions/collections-description/COP-DEM',
                 'Copernicus WorldDEM-30 licence conditions', 'copernicus', 0, '[]'::jsonb,
                 '© DLR e.V. 2010-2014 · © Airbus Defence and Space GmbH 2014-2018',
                 'credits.copernicus_p', 'installed on the host', FALSE, TRUE)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM data_provider WHERE provider_key IN ('overture', 'geofabrik', 'wikimedia-commons', 'wikipedia', 'copernicus-worlddem30')");
        $this->addSql("UPDATE data_provider SET attribution = NULL WHERE provider_key = 'osm'");
        $this->addSql('ALTER TABLE data_provider DROP COLUMN blurb');
        $this->addSql('ALTER TABLE data_provider DROP COLUMN blurb_key');
    }
}
