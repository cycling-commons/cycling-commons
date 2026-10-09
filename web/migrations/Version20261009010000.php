<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The counting columns of traffic_total are BIGINT, like distance_m and
 * time_s: a busy road's row adds up to 300 cars per line for as long as the
 * platform runs, and an INT sum that overflows would fail every release of
 * that road's block (traffic-measurements.md §4.3).
 */
final class Version20261009010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'traffic_total: passes, nearby, speed_passes and lines become BIGINT';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE traffic_total ALTER passes TYPE BIGINT, ALTER nearby TYPE BIGINT, ALTER speed_passes TYPE BIGINT, ALTER lines TYPE BIGINT');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE traffic_total ALTER passes TYPE INT, ALTER nearby TYPE INT, ALTER speed_passes TYPE INT, ALTER lines TYPE INT');
    }
}
