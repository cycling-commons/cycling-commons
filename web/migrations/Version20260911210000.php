<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The contributor counter needs a history, and a history needs a date.
 *
 * The spin-out trigger (wiki/governance.md commitment 3) counts "25 unique
 * external contributors", and the code half of that arrives from GitHub as
 * merged, approved pull requests. The page also promises a progress graph,
 * which needs to know *when* each person arrived — a bare set of logins can
 * answer "how many now" but never "how the count grew". So the sync stores
 * one row per login with the window of its merged PRs: first_pr_at is the
 * arrival date the graph plots, last_pr_at keeps the row honest about people
 * who come back.
 *
 * Keyed on the GitHub login, not an email: GitHub hides most author emails
 * behind per-account noreplies, and one person can commit under two.
 *
 * @see docs/specs/moderation-and-contribution.md
 */
final class Version20260911210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'create github_contributor: one row per merged, approved PR author, with the window of their merges';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE github_contributor (
                github_login VARCHAR(39) NOT NULL,
                first_pr_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                last_pr_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(github_login)
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE github_contributor');
    }
}
