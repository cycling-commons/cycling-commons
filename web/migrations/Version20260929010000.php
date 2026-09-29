<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every stored address in lower case, the form User::setEmail() now writes.
 *
 * Login and every other lookup lower-case the address they are given, so a
 * row still spelled `Rider@Example.com` could no longer sign in. A row whose
 * lower-case form already belongs to another account is left as it is: two
 * accounts on one mailbox is for a person to merge, not for a migration.
 *
 * @see docs/specs/account-and-auth.md §2
 */
final class Version20260929010000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users: addresses in lower case';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE users u SET email = lower(u.email)
            WHERE u.email <> lower(u.email)
              AND NOT EXISTS (SELECT 1 FROM users o WHERE o.id <> u.id AND lower(o.email) = lower(u.email))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The original spelling is gone, and nothing depended on it.
    }
}
