<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When a rider declared they were 16 or older, at registration.
 *
 * A timestamp rather than a date of birth: answering one yes/no question does
 * not need a birthday on file (GDPR Art. 5(1)(c)), and null/not-null already
 * carries the boolean. Existing accounts keep NULL — they registered before
 * the gate existed, and back-filling a declaration nobody made would be a
 * record of something that never happened.
 */
final class Version20260801090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.age_confirmed_at: the 16-or-older declaration made at registration';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD age_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN users.age_confirmed_at IS 'When the rider declared they were 16 or older (GDPR Art. 8). NULL = registered before the gate existed, or created by an admin/console path.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP age_confirmed_at');
    }
}
