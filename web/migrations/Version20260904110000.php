<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The authority registry, and the end of the `pivot` bucket.
 *
 * `item.source = 'pivot'` was a bucket named after its first member, the
 * Geoportail Wallonie accommodation register. It becomes `authority`, a rank
 * earned by a publisher who is the body of record for the thing being mapped,
 * and every row in it now points at a `data_provider` row that says who that
 * publisher is and what we owe them.
 *
 * OpenStreetMap and Wikidata are seeded here too, `system` so nobody can
 * delete them: one table has to answer "who do we cite, and under what
 * licence" for every row on the map, or the credits page drifts.
 *
 * `item.source` stays `varchar(10)`; "authority" is nine characters. Existing
 * `source_ref` values such as `fx:pivot:hotel-koru|ramillies` are left exactly
 * as they are: they are historical upsert keys, not display strings, and
 * rewriting them would break the one thing they are for.
 *
 * @see docs/specs/data-provider-hierarchy.md §3, §10
 */
final class Version20260904110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'data_provider registry; item.pivot becomes item.authority pointing at a registry row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE data_provider (
                id BIGSERIAL PRIMARY KEY,
                provider_key VARCHAR(64) NOT NULL,
                name VARCHAR(120) NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                homepage VARCHAR(500) NOT NULL,
                licence VARCHAR(255) NOT NULL,
                licence_code VARCHAR(40) NOT NULL,
                creator VARCHAR(255) DEFAULT NULL,
                promoted BOOLEAN NOT NULL DEFAULT FALSE,
                attribution TEXT DEFAULT NULL,
                country_code VARCHAR(2) DEFAULT NULL,
                letters JSONB NOT NULL DEFAULT '[]'::jsonb,
                rank INT NOT NULL,
                community_edited BOOLEAN NOT NULL DEFAULT FALSE,
                endpoint VARCHAR(500) DEFAULT NULL,
                endpoint_kind VARCHAR(20) DEFAULT NULL,
                field_map JSONB NOT NULL DEFAULT '{}'::jsonb,
                match_radius_m INT NOT NULL DEFAULT 50,
                refresh_cadence VARCHAR(60) DEFAULT NULL,
                last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_count INT DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                system BOOLEAN NOT NULL DEFAULT FALSE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_data_provider_key ON data_provider (provider_key)');

        // The two harvests that are their own code. Their endpoint and
        // field_map stay NULL and empty on purpose: they are here to be cited,
        // not to be fetched. Ranks are today's ladder, so admitting the
        // registry moves nothing a rider can see.
        $this->addSql(<<<'SQL'
            INSERT INTO data_provider
                (provider_key, name, full_name, homepage, licence, licence_code, rank, letters, system, enabled)
            VALUES
                ('osm', 'OpenStreetMap', 'OpenStreetMap contributors',
                 'https://www.openstreetmap.org/copyright',
                 'Open Database License (ODbL) 1.0', 'odbl', 100, '[]'::jsonb, TRUE, TRUE),
                ('wikidata', 'Wikidata', 'Wikidata, the free knowledge base',
                 'https://www.wikidata.org/',
                 'Creative Commons CC0 1.0', 'cc0-1.0', 200, '[]'::jsonb, TRUE, TRUE)
            SQL);

        // The first authority, and the reason the bucket existed at all. Its
        // harvest is still tools/wallonia/pivot.py until the generic harvester
        // replaces it, so endpoint stays NULL rather than claiming a fetch
        // nothing performs.
        $this->addSql(<<<'SQL'
            INSERT INTO data_provider
                (provider_key, name, full_name, homepage, licence, licence_code, rank,
                 country_code, letters, attribution, refresh_cadence, system, enabled)
            VALUES
                ('wallonie-pivot', 'Tourisme Wallonie',
                 'PIVOT, Commissariat général au Tourisme de Wallonie',
                 'https://geoportail.wallonie.be/catalogue/',
                 'Creative Commons BY 4.0', 'cc-by-4.0', 300,
                 'BE', '["I"]'::jsonb, 'Tourisme Wallonie (CC-BY)', 'yearly', FALSE, TRUE)
            SQL);

        $this->addSql('ALTER TABLE item ADD provider_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD CONSTRAINT fk_item_provider FOREIGN KEY (provider_id) REFERENCES data_provider (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_item_provider ON item (provider_id)');

        // The rename and the pointer in one statement, so no row is ever
        // `authority` without a registry row behind it.
        $this->addSql(<<<'SQL'
            UPDATE item
               SET source = 'authority',
                   provider_id = (SELECT id FROM data_provider WHERE provider_key = 'wallonie-pivot')
             WHERE source = 'pivot'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE item SET source = 'pivot', provider_id = NULL WHERE source = 'authority'");
        $this->addSql('DROP INDEX idx_item_provider');
        $this->addSql('ALTER TABLE item DROP CONSTRAINT fk_item_provider');
        $this->addSql('ALTER TABLE item DROP COLUMN provider_id');
        $this->addSql('DROP TABLE data_provider');
    }
}
