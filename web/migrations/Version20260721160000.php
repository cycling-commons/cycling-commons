<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: optional coarse base location (point/place/radius) + derived region/country sets (region-scoping-design.md §3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD base_point geometry(Geometry, 4326) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD base_place VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD base_radius_km SMALLINT DEFAULT 40 NOT NULL');
        $this->addSql('ALTER TABLE users ALTER COLUMN base_radius_km DROP DEFAULT');
        $this->addSql("ALTER TABLE users ADD base_region_ids JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE users ALTER COLUMN base_region_ids DROP DEFAULT');
        $this->addSql("ALTER TABLE users ADD base_country_codes JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE users ALTER COLUMN base_country_codes DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP base_point');
        $this->addSql('ALTER TABLE users DROP base_place');
        $this->addSql('ALTER TABLE users DROP base_radius_km');
        $this->addSql('ALTER TABLE users DROP base_region_ids');
        $this->addSql('ALTER TABLE users DROP base_country_codes');
    }
}
