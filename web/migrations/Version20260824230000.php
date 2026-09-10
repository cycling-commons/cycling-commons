<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'item.osm_ref (link every source back to OSM) + catalog_finding (the curator data desk)';
    }

    public function up(Schema $schema): void
    {
        // ---- item.osm_ref -------------------------------------------------
        //
        // OSM is the identity spine (osm-data-architecture.md §1: "The join key
        // between our data and OSM is always osm_ref"). A rider editing an OSM
        // object already gets that for free — CatalogContributionService writes
        // the OSM ref as the new row's own source_ref. PIVOT and Wikidata never
        // did, so their rows carry refs like `fx:pivot:hotel-koru|ramillies`
        // that can never match `node/6123208864`, and one hotel is served twice:
        // once from the catalog, once from the coverage cache, because that
        // dedupe is by ref (owner decision 2026-08-24).
        //
        // A separate column rather than reusing source_ref: source_ref is the
        // upsert key a re-import needs to find its own row again
        // (`ON CONFLICT (source, source_ref, letter)`), so it has to stay the
        // harvest's own identifier. osm_ref is what that row is a record OF.
        $this->addSql('ALTER TABLE item ADD COLUMN osm_ref VARCHAR(160) DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN item.osm_ref IS 'The OSM object this row records, whatever our own source is. NULL when there is no OSM counterpart.'");

        // Deliberately NOT unique yet. The existing duplicates have to be
        // cleared first (app:catalog:dedupe), or the constraint would be a
        // migration that cannot run. Tightening it to a partial unique index
        // over served rows is the follow-up once the data is clean.
        $this->addSql('CREATE INDEX idx_item_osm_ref ON item (osm_ref) WHERE osm_ref IS NOT NULL');

        // ---- catalog_finding ----------------------------------------------
        //
        // Machine-raised findings about catalog DATA, for the curator data desk.
        //
        // Not submissions, and deliberately a separate table: a submission is a
        // person's contribution, carries a rider identity, sends messages on
        // decision, and rides the retention clock. None of that is true here —
        // nobody proposed these, a scan did. Putting them in the submission
        // queue would mean moderators owe a reply to a machine.
        //
        // `kind` is what keeps this ONE desk instead of a desk per check
        // (one-way-to-moderate): the duplicate scan and the OSM-link matcher
        // are the first two, and the checks prescreen_seeded.py already
        // computes (coord drift, an area seeded as one point, a row filed under
        // the wrong country) drop in behind them with no new mechanics.
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog_finding (
                id              BIGSERIAL PRIMARY KEY,
                kind            VARCHAR(32)  NOT NULL,
                item_id         BIGINT       NOT NULL,
                related_item_id BIGINT       DEFAULT NULL,
                osm_ref         VARCHAR(160) DEFAULT NULL,
                detail          JSONB        NOT NULL DEFAULT '{}'::jsonb,
                status          VARCHAR(12)  NOT NULL DEFAULT 'open',
                decided_by      BIGINT       DEFAULT NULL,
                decided_at      TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                note            VARCHAR(500) DEFAULT NULL,
                created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);

        // A finding follows its item: nothing should survive the row it is about.
        $this->addSql('ALTER TABLE catalog_finding ADD CONSTRAINT fk_finding_item FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE catalog_finding ADD CONSTRAINT fk_finding_related FOREIGN KEY (related_item_id) REFERENCES item (id) ON DELETE CASCADE');

        // Re-running a scan must UPDATE the finding it already raised, never add
        // a second copy. NULLS NOT DISTINCT is the point: most kinds leave one
        // of related_item_id / osm_ref null, and the default NULL-is-distinct
        // rule would let the same finding in again on every refresh.
        $this->addSql('CREATE UNIQUE INDEX uniq_finding_identity ON catalog_finding (kind, item_id, related_item_id, osm_ref) NULLS NOT DISTINCT');

        // The desk lists open findings; the counter in the moderator tab bar
        // counts them per scope.
        $this->addSql("CREATE INDEX idx_finding_open ON catalog_finding (kind, id) WHERE status = 'open'");

        // No region_id or country_code column on purpose: scope is read through
        // the item (`item.region_id` / `item.country_code`), which the import
        // recomputes from geometry on every run. A copy here would be a second
        // truth that drifts the first time a region boundary moves.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS catalog_finding');
        $this->addSql('DROP INDEX IF EXISTS idx_item_osm_ref');
        $this->addSql('ALTER TABLE item DROP COLUMN IF EXISTS osm_ref');
    }
}
