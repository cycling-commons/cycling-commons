<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: case-insensitive unique display names (canonical shadow column) + bike_types/riding_styles preference columns';
    }

    public function up(Schema $schema): void
    {
        // 1. Shadow column (stays nullable: empty display names canonicalize
        //    to NULL, so unnamed rows never participate in uniqueness).
        $this->addSql('ALTER TABLE users ADD display_name_canonical VARCHAR(100)');
        $this->addSql(<<<'SQL'
            UPDATE users SET display_name_canonical = NULLIF(LOWER(TRIM(display_name)), '')
            SQL);

        // 2. De-duplicate existing case-insensitive collisions by suffixing
        //    (name, name-2, name-3, …). Loops because a suffixed name could
        //    itself collide with an existing row; LEFT(…, 96) keeps the
        //    suffixed value inside VARCHAR(100). No production DB exists yet,
        //    so this only ever touches dev data — but it is order-safe anyway.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              LOOP
                WITH ranked AS (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY display_name_canonical ORDER BY id
                    ) AS rn
                    FROM users
                    WHERE display_name_canonical IS NOT NULL
                )
                UPDATE users u
                SET display_name = LEFT(u.display_name, 96) || '-' || r.rn,
                    display_name_canonical = LEFT(u.display_name_canonical, 96) || '-' || r.rn
                FROM ranked r
                WHERE u.id = r.id AND r.rn > 1;
                EXIT WHEN NOT FOUND;
              END LOOP;
            END $$
            SQL);

        // 3. Unique index; NULLs (unnamed rows) are ignored by Postgres, so
        //    only real names participate in uniqueness.
        $this->addSql('CREATE UNIQUE INDEX uniq_users_display_name_canonical ON users (display_name_canonical)');

        // 4. Preference columns: DEFAULT backfills existing rows, then the
        //    default is dropped to match the Doctrine mapping (no default).
        $this->addSql(<<<'SQL'
            ALTER TABLE users ADD bike_types JSON DEFAULT '[]' NOT NULL
            SQL);
        $this->addSql('ALTER TABLE users ALTER COLUMN bike_types DROP DEFAULT');
        $this->addSql(<<<'SQL'
            ALTER TABLE users ADD riding_styles JSON DEFAULT '[]' NOT NULL
            SQL);
        $this->addSql('ALTER TABLE users ALTER COLUMN riding_styles DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_users_display_name_canonical');
        $this->addSql('ALTER TABLE users DROP display_name_canonical');
        $this->addSql('ALTER TABLE users DROP bike_types');
        $this->addSql('ALTER TABLE users DROP riding_styles');
    }
}
