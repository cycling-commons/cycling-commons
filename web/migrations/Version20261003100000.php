<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Something else" on the report form, and the rule a curator names for it.
 *
 * A report filed as "Something else" (`ground = 'other'`) names no rule in
 * our terms. A curator who upholds it picks the rule it breaks, stored here,
 * and the statement of reasons (DSA Article 17) names that rule.
 *
 * @see docs/specs/content-reports.md §4
 */
final class Version20261003100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'content_report: rule_ground, the rule an upheld "Something else" report breaks';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report ADD rule_ground VARCHAR(32) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report DROP rule_ground');
    }
}
