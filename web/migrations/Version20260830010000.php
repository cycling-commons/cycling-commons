<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The rights claim and the answer to it.
 *
 * Backlog item 6: photos arrive under CC BY-SA 4.0 on the contributor's word
 * that the work is theirs, and when that word is wrong the rights holder had
 * nowhere to go but the general address. A copyright complaint is the same
 * shape as every other report, so it becomes a ground on the one form rather
 * than a second flow, and needs three things the other grounds do not.
 *
 * `work_original` is where the original is: a URL if it was ever online, a
 * description of the work if it was not. `claimant_name` is the name the claim
 * is made under, and unlike `reporter_contact`, which nobody ever sees, this
 * one IS shown to the uploader, because a person cannot answer a claim without
 * knowing who makes it.
 *
 * `counter_notice` is what the uploader says back after the Article 17
 * statement of reasons, on the same row rather than as a new report. Once: the
 * link that reaches it was mailed once. There is no automatic put-back clock;
 * the fortnight in US DMCA 512(g) is a safe-harbour mechanism we are not
 * obliged to run, so a person decides with both sides in front of them.
 *
 * @see docs/specs/2026-08-30-one-report-route-design.md §3
 */
final class Version20260830010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'content_report: the rights claim (work, claimant) and the uploader answer to it.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report ADD work_original TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE content_report ADD claimant_name VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_report ADD counter_notice TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE content_report ADD counter_notice_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report DROP counter_notice_at');
        $this->addSql('ALTER TABLE content_report DROP counter_notice');
        $this->addSql('ALTER TABLE content_report DROP claimant_name');
        $this->addSql('ALTER TABLE content_report DROP work_original');
    }
}
