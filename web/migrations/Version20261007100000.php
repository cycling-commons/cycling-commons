<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The last good run of each daily job, for the admin dashboard's warning.
 *
 * @see docs/specs/operations.md (daily jobs)
 */
final class Version20261007100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'job_run: last good run per daily console job';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE job_run (command VARCHAR(80) NOT NULL, last_success_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (command))');
        $this->addSql("COMMENT ON TABLE job_run IS 'Last good run of each daily console job; the admin dashboard warns when one is late.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job_run');
    }
}
