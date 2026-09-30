<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * catalog_change triggers on region: a route row names every region its line
 * passes through (CatalogProvider::routeRegionsSql()), read from the region
 * outlines.
 *
 * A route in one region can gain or lose a neighbour when any outline moves,
 * so a change counts for every region (-1). Only an outline that really
 * changed counts: renames, caps and map modes are not in that list, and a
 * world import that rewrites the same geometry sends nothing. Outlines change
 * when a country is onboarded, which is rare.
 *
 * @see docs/specs/catalog-data-model.md §9.1
 */
final class Version20260930010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'catalog_change: a region outline added, moved or removed counts everywhere';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TRIGGER catalog_change_region_ins AFTER INSERT ON region
            REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION catalog_change_everywhere_rows()');
        $this->addSql('CREATE TRIGGER catalog_change_region_upd AFTER UPDATE OF geom ON region FOR EACH ROW
            WHEN (OLD.geom IS DISTINCT FROM NEW.geom) EXECUTE FUNCTION catalog_change_everywhere()');
        $this->addSql('CREATE TRIGGER catalog_change_region_del AFTER DELETE ON region
            REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION catalog_change_everywhere_rows()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (['ins', 'upd', 'del'] as $op) {
            $this->addSql("DROP TRIGGER IF EXISTS catalog_change_region_{$op} ON region");
        }
    }
}
