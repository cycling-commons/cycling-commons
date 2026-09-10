<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Display names stop being unique (account-and-auth.md §9).
 *
 * Two riders may genuinely share a name — John Doe is a common one — and
 * telling the second to pick something else amounts to telling them their own
 * name is somebody else's property. The uuid was always the identity; the
 * uniqueness rule only made the name look like one.
 *
 * The shadow column goes with the constraint it existed for. Keeping a
 * canonical form around would keep implying that names identify accounts,
 * and nothing else ever read it: display names are used for display, never as
 * a lookup key.
 */
final class Version20260731150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Display names are no longer unique: drop uniq_users_display_name_canonical and the shadow column';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_users_display_name_canonical');
        $this->addSql('ALTER TABLE users DROP COLUMN display_name_canonical');
    }

    /**
     * Reversible only while no two accounts actually share a name — which is
     * the whole point of the change, so a down() on live data may well fail.
     * The column is restored empty rather than recomputed: rebuilding it would
     * need the canonicalization rule this migration deletes.
     */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD display_name_canonical VARCHAR(100) DEFAULT NULL');
        $this->addSql('UPDATE users SET display_name_canonical = NULLIF(LOWER(BTRIM(display_name)), \'\')');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_display_name_canonical ON users (display_name_canonical)');
    }
}
