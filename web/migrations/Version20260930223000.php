<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Town and region texts are proposed by anyone signed in and approved by a
 * curator of that region, through the ordinary submission queue
 * (docs/specs/moderation-and-contribution.md §3.1, owner 2026-09-30).
 *
 * `town_place` is where a town lies: the point the first town card reader's
 * map named for it, kept once. It is what decides which region, and so which
 * curators, a town text belongs to. A later reader cannot move it.
 *
 * `town_summary.approved_by` and `town_summary.submission_id` record who let a
 * local text onto the card and which proposal it came from; `edited_by` stays
 * the person who wrote it. Rows written before this change keep NULL in both.
 */
final class Version20260930223000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'town_place: where each town lies; town_summary: who approved a local text and from which submission';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE town_place (
                osm_ref VARCHAR(32) NOT NULL PRIMARY KEY,
                lat DOUBLE PRECISION NOT NULL,
                lng DOUBLE PRECISION NOT NULL,
                recorded_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql("COMMENT ON TABLE town_place IS 'Where a town lies, one row per OpenStreetMap ref: the point the first town card reader''s map named for it, kept once. It decides which region a town text belongs to.'");
        $this->addSql('ALTER TABLE town_summary ADD approved_by BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE town_summary ADD submission_id BIGINT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN town_summary.approved_by IS 'The curator who let a local text onto the card: the approver of a proposal, or the curator who wrote it on the town page. NULL for a fetched row.'");
        $this->addSql("COMMENT ON COLUMN town_summary.submission_id IS 'The approved text proposal this local text came from; NULL when a curator wrote it on the town page.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_summary DROP submission_id');
        $this->addSql('ALTER TABLE town_summary DROP approved_by');
        $this->addSql('DROP TABLE town_place');
    }
}
