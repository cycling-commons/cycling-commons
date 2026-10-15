<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `website_check` (docs/specs/website-liveness.md section 4): one row per
 * website host with the last verdict (live, parked, dead, unknown), the
 * address checked, the final status, the reason, when it was checked and
 * when it is due again. The sweep, the wizard and the provider ingest read
 * and write it; the serve paths read it in bulk. A verdict that crosses into
 * or out of dead/parked moves every region's catalog stamp
 * (catalog_change_everywhere, Version20260928150000), because the map serves
 * an item without its website from then on (catalog-data-model.md section
 * 9.1): a document built before the change must not be served after it.
 */
final class Version20261015010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'website_check: one verdict per website host, with when it is due again.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE website_check (
            host VARCHAR(253) NOT NULL PRIMARY KEY,
            url VARCHAR(500) NOT NULL,
            verdict VARCHAR(8) NOT NULL,
            status SMALLINT DEFAULT NULL,
            reason VARCHAR(40) DEFAULT NULL,
            checked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            next_check_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            CONSTRAINT website_check_verdict_check CHECK (verdict IN ('live', 'parked', 'dead', 'unknown'))
        )");
        $this->addSql('CREATE INDEX idx_website_check_due ON website_check (next_check_at)');
        $this->addSql("COMMENT ON TABLE website_check IS 'One verdict per website host: live, parked, dead or unknown, the address checked, and when it is due again (docs/specs/website-liveness.md).'");
        $this->addSql("CREATE TRIGGER catalog_change_website_check_ins AFTER INSERT ON website_check
            FOR EACH ROW WHEN (NEW.verdict IN ('dead', 'parked')) EXECUTE FUNCTION catalog_change_everywhere()");
        $this->addSql("CREATE TRIGGER catalog_change_website_check_upd AFTER UPDATE ON website_check
            FOR EACH ROW WHEN ((OLD.verdict IN ('dead', 'parked')) IS DISTINCT FROM (NEW.verdict IN ('dead', 'parked'))) EXECUTE FUNCTION catalog_change_everywhere()");
        $this->addSql("CREATE TRIGGER catalog_change_website_check_del AFTER DELETE ON website_check
            FOR EACH ROW WHEN (OLD.verdict IN ('dead', 'parked')) EXECUTE FUNCTION catalog_change_everywhere()");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE website_check');
    }
}
