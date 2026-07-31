<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'map view-mode default: region.curated_default (moderator flag, threshold-gated) + users.default_map_mode (rider preference) — map-and-search.md §4.2';
    }

    public function up(Schema $schema): void
    {
        // Authored by a moderator, not derived — unlike adj/outline, nothing
        // recomputes this. NOT NULL with a false default: "this region has not
        // earned Curated-by-default" is the correct state for every existing
        // region, and there is no meaningful third value.
        $this->addSql('ALTER TABLE region ADD curated_default BOOLEAN DEFAULT FALSE NOT NULL');
        // 'auto' = follow the region. Stored as the MapViewMode value string.
        $this->addSql("ALTER TABLE users ADD default_map_mode VARCHAR(16) DEFAULT 'auto' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP curated_default');
        $this->addSql('ALTER TABLE users DROP default_map_mode');
    }
}
