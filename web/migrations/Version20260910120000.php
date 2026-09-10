<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Custody moves both ways (docs/specs/data-provider-hierarchy.md §6.7.2).
 *
 * Three per-provider settings on the registry row: whether the provider may
 * take a record back at all, the margin in days its survey must be newer by,
 * and which harvested attribute carries the survey date. One column on the
 * item: the survey date that took custody back, written only at harvest. No
 * confirmation row is touched by any of it.
 */
final class Version20260910120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'data_provider: may_reclaim, reclaim_margin_days, survey_date_attribute; item: custody_reclaimed_at';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_provider ADD may_reclaim BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE data_provider ADD reclaim_margin_days INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE data_provider ADD survey_date_attribute VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD custody_reclaimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP custody_reclaimed_at');
        $this->addSql('ALTER TABLE data_provider DROP survey_date_attribute');
        $this->addSql('ALTER TABLE data_provider DROP reclaim_margin_days');
        $this->addSql('ALTER TABLE data_provider DROP may_reclaim');
    }
}
