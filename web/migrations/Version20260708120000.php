<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Route domain v1 phase 1 (spec §4.1): recommended_route.proposed_by — the
 * proposing rider's users.id for rider-proposed routes (NULL for imports).
 */
final class Version20260708120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recommended_route.proposed_by (rider proposals, route-domain v1 phase 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommended_route ADD proposed_by INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_route_proposed_by ON recommended_route (proposed_by)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_route_proposed_by');
        $this->addSql('ALTER TABLE recommended_route DROP proposed_by');
    }
}
