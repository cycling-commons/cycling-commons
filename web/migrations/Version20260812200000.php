<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A region opens in one of THREE modes now, so the flag becomes a value.
 *
 * `curated_default` was a boolean because the map had two modes. It has three
 * (owner decision 2026-08-12: Best of · Confirmed · Everything), and a second
 * boolean beside the first would let a region claim both at once. One column,
 * one answer: `everything` (the default every region starts in), `confirmed`,
 * or `curated`.
 *
 * The old flag's meaning is carried over exactly — a region set to open in the
 * best-of keeps doing so — and then dropped, because two sources of truth for
 * one question is how they drift.
 */
final class Version20260812200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region.default_map_mode (everything|confirmed|curated) replaces region.curated_default — map-and-search.md §4.2';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE region ADD default_map_mode VARCHAR(12) DEFAULT 'everything' NOT NULL");
        $this->addSql("UPDATE region SET default_map_mode = 'curated' WHERE curated_default = TRUE");
        $this->addSql('ALTER TABLE region DROP curated_default');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD curated_default BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("UPDATE region SET curated_default = TRUE WHERE default_map_mode = 'curated'");
        $this->addSql('ALTER TABLE region DROP default_map_mode');
    }
}
