<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How long a page of any list is (docs/specs/account-and-auth.md §9).
 *
 * Display only, like the unit and format preferences beside it. `auto` — the
 * default, and what every existing row gets — means each list keeps the size
 * it was designed around, which is deliberately not one number: a message is a
 * card, a moderation queue item is a row of work, a contributors-wall row is
 * one line. An explicit 25/50/100 overrides all of them at once.
 */
final class Version20260809120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.rows_per_page: how long a page of any list is, display only';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD rows_per_page VARCHAR(8) DEFAULT 'auto' NOT NULL");
        $this->addSql("COMMENT ON COLUMN users.rows_per_page IS 'auto|25|50|100 — App\\Account\\RowsPerPage. Display only; auto lets each list keep its own default.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP rows_per_page');
    }
}
