<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Link verdicts no longer move the map's catalog stamps
 * (docs/specs/catalog-data-model.md §7, owner 2026-10-10): the map shows a
 * link whatever its verdict, and the verdict only warns the curator who
 * reviews it. The three triggers from Version20260928150000 made every region
 * rebuild when a URL crossed into or out of unsafe; they go. The shared
 * function catalog_change_everywhere() stays: other triggers use it.
 */
final class Version20261010020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the link_verdict catalog stamp triggers';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (['ins', 'upd', 'del'] as $op) {
            $this->addSql("DROP TRIGGER IF EXISTS catalog_change_link_verdict_{$op} ON link_verdict");
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("CREATE TRIGGER catalog_change_link_verdict_ins AFTER INSERT ON link_verdict
            FOR EACH ROW WHEN (NEW.verdict = 'unsafe') EXECUTE FUNCTION catalog_change_everywhere()");
        $this->addSql("CREATE TRIGGER catalog_change_link_verdict_upd AFTER UPDATE ON link_verdict
            FOR EACH ROW WHEN ((OLD.verdict = 'unsafe') IS DISTINCT FROM (NEW.verdict = 'unsafe')) EXECUTE FUNCTION catalog_change_everywhere()");
        $this->addSql("CREATE TRIGGER catalog_change_link_verdict_del AFTER DELETE ON link_verdict
            FOR EACH ROW WHEN (OLD.verdict = 'unsafe') EXECUTE FUNCTION catalog_change_everywhere()");
    }
}
