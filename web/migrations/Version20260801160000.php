<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How a rider wants dates written (docs/specs/account-and-auth.md §9).
 *
 * Defaults to 'auto', which follows the interface language — so every existing
 * account keeps a sensible format without a backfill, and the column only ever
 * holds something else because somebody chose it.
 */
final class Version20260801160000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.date_format: the rider\'s preferred date notation';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD date_format VARCHAR(10) DEFAULT 'auto' NOT NULL");
        $this->addSql("COMMENT ON COLUMN users.date_format IS 'auto|ymd|dmy|mdy|long — App\\Account\\DateFormat. auto follows the interface language.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP date_format');
    }
}
