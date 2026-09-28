<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The database counts its own catalog changes, per region.
 *
 * Every statement that changes what a region's catalog rows serialize to
 * appends one row to `catalog_change` for each region it touched, from a
 * statement-level trigger. A region's change count is the sum of `n` over its
 * rows. It only grows, and only when a transaction commits, so it is the
 * collision-proof stamp catalog-data-model.md §9.1 asked for: the old stamp
 * (row count + latest timestamp) missed two edits in the same second.
 *
 * Append-only on purpose. A counter row updated in place would be locked by
 * every writer until it commits, so a long harvest would stall every rider's
 * confirmation in that region behind it. Inserts never wait for each other.
 * CatalogStamps::compact() folds the rows into one per region; the sums do not
 * change, so neither does any stamp.
 *
 * `region_id` is the item's region, 0 for rows that belong to no region, and
 * -1 for a change every region serializes (a contributor's public name, a
 * provider's citation, a place name, an unsafe link, an OSM photo tag).
 *
 * coverage_poi is created by the coverage pipeline, not by a migration, so its
 * trigger is installed by catalog_change_install(), which the pipeline calls
 * after it creates the table (pipeline/coverage/load.py ensure_schema()).
 *
 * @see docs/specs/catalog-data-model.md §9.1
 */
final class Version20260928150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'catalog_change: a per-region change count the database keeps by trigger';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // No serial id, on purpose. The triggers insert here inside the
        // statement that wrote an item, and a sequence used by that insert
        // becomes the session's lastval(), which is what Doctrine reads back as
        // the new item's id.
        $this->addSql('CREATE TABLE catalog_change (region_id INT NOT NULL, n BIGINT DEFAULT 1 NOT NULL)');

        // Rows that carry their own region: item, recommended_route.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_change_on_region() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    INSERT INTO catalog_change (region_id) SELECT DISTINCT COALESCE(region_id, 0) FROM new_rows;
                ELSIF TG_OP = 'DELETE' THEN
                    INSERT INTO catalog_change (region_id) SELECT DISTINCT COALESCE(region_id, 0) FROM old_rows;
                ELSE
                    -- Both sides: a row that moved region changed two regions.
                    INSERT INTO catalog_change (region_id)
                        SELECT COALESCE(region_id, 0) FROM old_rows UNION SELECT COALESCE(region_id, 0) FROM new_rows;
                END IF;
                RETURN NULL;
            END $$
            SQL);

        // Rows that reach a region through their item: confirmations (freshness,
        // evidence), submissions (the contributor line) and history (whether an
        // OSM row is still untouched, CoverageRetirement).
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_change_on_item_child() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE ids int[];
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT array_agg(DISTINCT item_id) INTO ids FROM new_rows WHERE item_id IS NOT NULL;
                ELSIF TG_OP = 'DELETE' THEN
                    SELECT array_agg(DISTINCT item_id) INTO ids FROM old_rows WHERE item_id IS NOT NULL;
                ELSE
                    SELECT array_agg(DISTINCT item_id) INTO ids
                      FROM (SELECT item_id FROM old_rows UNION SELECT item_id FROM new_rows) u WHERE item_id IS NOT NULL;
                END IF;
                IF ids IS NULL THEN RETURN NULL; END IF;
                INSERT INTO catalog_change (region_id)
                    SELECT DISTINCT COALESCE(i.region_id, 0) FROM item i WHERE i.id = ANY (ids);
                RETURN NULL;
            END $$
            SQL);

        // Row-level: the WHEN clause of each trigger below has already said
        // that this row changed something the payload prints.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_change_everywhere() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO catalog_change (region_id) VALUES (-1);
                RETURN NULL;
            END $$
            SQL);
        // Statement-level, for a bulk writer: once per statement that touched a row.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_change_everywhere_rows() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF NOT EXISTS (SELECT 1 FROM old_rows) THEN RETURN NULL; END IF;
                ELSIF NOT EXISTS (SELECT 1 FROM new_rows) THEN
                    RETURN NULL;
                END IF;
                INSERT INTO catalog_change (region_id) VALUES (-1);
                RETURN NULL;
            END $$
            SQL);

        foreach (['item', 'recommended_route'] as $table) {
            $this->regionTriggers($table, 'catalog_change_on_region');
        }
        foreach (['item_confirmation', 'submission', 'change_history'] as $table) {
            $this->regionTriggers($table, 'catalog_change_on_item_child');
        }

        // Read by every region's rows, so only a row whose printed columns
        // really changed counts: a login (users.last_login_at), a harvest's
        // bookkeeping (data_provider.last_run_at) or a world import that
        // rewrites the same names sends no rider their regions again.
        $this->addSql('CREATE TRIGGER catalog_change_users_upd AFTER UPDATE ON users FOR EACH ROW
            WHEN (OLD.display_name IS DISTINCT FROM NEW.display_name OR OLD.public_profile IS DISTINCT FROM NEW.public_profile
                  OR OLD.uuid IS DISTINCT FROM NEW.uuid)
            EXECUTE FUNCTION catalog_change_everywhere()');
        $this->addSql('CREATE TRIGGER catalog_change_users_del AFTER DELETE ON users FOR EACH ROW EXECUTE FUNCTION catalog_change_everywhere()');
        $this->addSql('CREATE TRIGGER catalog_change_data_provider_upd AFTER UPDATE ON data_provider FOR EACH ROW
            WHEN ((OLD.provider_key, OLD.name, OLD.full_name, OLD.homepage, OLD.licence, OLD.attribution, OLD.creator,
                   OLD.enabled, OLD.rank, OLD.letters, OLD.survey_date_attribute)
                  IS DISTINCT FROM (NEW.provider_key, NEW.name, NEW.full_name, NEW.homepage, NEW.licence, NEW.attribution, NEW.creator,
                   NEW.enabled, NEW.rank, NEW.letters, NEW.survey_date_attribute))
            EXECUTE FUNCTION catalog_change_everywhere()');
        $this->addSql('CREATE TRIGGER catalog_change_data_provider_ins AFTER INSERT ON data_provider FOR EACH ROW EXECUTE FUNCTION catalog_change_everywhere()');
        $this->addSql('CREATE TRIGGER catalog_change_data_provider_del AFTER DELETE ON data_provider FOR EACH ROW EXECUTE FUNCTION catalog_change_everywhere()');
        // Only the name is printed (the `prov` line). A new subdivision or a
        // removed one reaches the payload through item.subdivision_id, whose
        // own trigger counts it.
        $this->addSql('CREATE TRIGGER catalog_change_world_subdivision AFTER UPDATE ON world_subdivision FOR EACH ROW
            WHEN (OLD.name IS DISTINCT FROM NEW.name) EXECUTE FUNCTION catalog_change_everywhere()');
        // A link verdict matters to the payload only when a URL enters or
        // leaves the unsafe set (LinkVerdictStore::withhold()). The checker
        // rewrites every verdict it rechecks, so a statement trigger would
        // fire on every run; these fire on the rows that crossed.
        $this->addSql("CREATE TRIGGER catalog_change_link_verdict_ins AFTER INSERT ON link_verdict
            FOR EACH ROW WHEN (NEW.verdict = 'unsafe') EXECUTE FUNCTION catalog_change_everywhere()");
        $this->addSql("CREATE TRIGGER catalog_change_link_verdict_upd AFTER UPDATE ON link_verdict
            FOR EACH ROW WHEN ((OLD.verdict = 'unsafe') IS DISTINCT FROM (NEW.verdict = 'unsafe')) EXECUTE FUNCTION catalog_change_everywhere()");
        $this->addSql("CREATE TRIGGER catalog_change_link_verdict_del AFTER DELETE ON link_verdict
            FOR EACH ROW WHEN (OLD.verdict = 'unsafe') EXECUTE FUNCTION catalog_change_everywhere()");

        // An OSM point's photo tags (CatalogProvider::osmPhotoRefs()).
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_change_install() RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                IF to_regclass('coverage_poi') IS NULL THEN RETURN; END IF;
                CREATE OR REPLACE TRIGGER catalog_change_coverage_poi_ins AFTER INSERT ON coverage_poi
                    REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION catalog_change_everywhere_rows();
                CREATE OR REPLACE TRIGGER catalog_change_coverage_poi_upd AFTER UPDATE ON coverage_poi
                    REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION catalog_change_everywhere_rows();
                CREATE OR REPLACE TRIGGER catalog_change_coverage_poi_del AFTER DELETE ON coverage_poi
                    REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION catalog_change_everywhere_rows();
            END $$
            SQL);
        $this->addSql('SELECT catalog_change_install()');

        // Every region that holds a row today starts with a count, so the
        // stamps list it from the first read.
        $this->addSql('INSERT INTO catalog_change (region_id)
            SELECT COALESCE(region_id, 0) FROM item UNION SELECT COALESCE(region_id, 0) FROM recommended_route UNION SELECT -1');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (['ins', 'upd', 'del'] as $op) {
            $this->addSql("DROP TRIGGER IF EXISTS catalog_change_coverage_poi_{$op} ON coverage_poi");
        }
        foreach (['item', 'recommended_route', 'item_confirmation', 'submission', 'change_history'] as $table) {
            foreach (['ins', 'upd', 'del'] as $op) {
                $this->addSql("DROP TRIGGER IF EXISTS catalog_change_{$table}_{$op} ON {$table}");
            }
        }
        $this->addSql('DROP TRIGGER IF EXISTS catalog_change_users_upd ON users');
        $this->addSql('DROP TRIGGER IF EXISTS catalog_change_users_del ON users');
        foreach (['ins', 'upd', 'del'] as $op) {
            $this->addSql("DROP TRIGGER IF EXISTS catalog_change_data_provider_{$op} ON data_provider");
        }
        $this->addSql('DROP TRIGGER IF EXISTS catalog_change_world_subdivision ON world_subdivision');
        foreach (['ins', 'upd', 'del'] as $op) {
            $this->addSql("DROP TRIGGER IF EXISTS catalog_change_link_verdict_{$op} ON link_verdict");
        }
        foreach (['catalog_change_install()', 'catalog_change_everywhere_rows()', 'catalog_change_everywhere()', 'catalog_change_on_item_child()', 'catalog_change_on_region()'] as $fn) {
            $this->addSql("DROP FUNCTION IF EXISTS {$fn}");
        }
        $this->addSql('DROP TABLE catalog_change');
    }

    /** Statement-level INSERT, UPDATE and DELETE triggers, each with the transition tables its event has. */
    private function regionTriggers(string $table, string $function): void
    {
        $this->addSql("CREATE TRIGGER catalog_change_{$table}_ins AFTER INSERT ON {$table}
            REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION {$function}()");
        $this->addSql("CREATE TRIGGER catalog_change_{$table}_upd AFTER UPDATE ON {$table}
            REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION {$function}()");
        $this->addSql("CREATE TRIGGER catalog_change_{$table}_del AFTER DELETE ON {$table}
            REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION {$function}()");
    }
}
