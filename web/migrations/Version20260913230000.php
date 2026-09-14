<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A curator can volunteer for an area the Commons does not have yet.
 *
 * `requested_region_id` can only name a `region` row, so the scope picker
 * offered the country and the regions already on the map: a rider willing to
 * run Ohio had to ask to run the whole United States, and the one signal worth
 * having, somebody who will do the work for a named place, could not be given
 * (owner 2026-09-13: "If there is a curator that is also a promoter we are
 * very willing to add it").
 *
 * Text, for the same reason as `country_interest.region_name`: the area being
 * volunteered for has no row yet, which is the point. Empty means the whole
 * country, so the column never has to be read as three-valued.
 *
 * @see docs/specs/moderation-and-contribution.md §11.2
 */
final class Version20260913230000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'curator_application can name an area that has no region row yet';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE curator_application ADD COLUMN requested_area VARCHAR(120) DEFAULT '' NOT NULL");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curator_application DROP COLUMN requested_area');
    }
}
