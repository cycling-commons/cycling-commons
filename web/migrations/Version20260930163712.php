<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every account stores its own random pseudonym (owner 2026-09-30).
 *
 * `users.pseudonym` holds the eight characters after `rider#`: Crockford
 * base32 in lower case without i, l, o and u. The User entity draws one when
 * the account is created and never changes it (App\Catalog\RiderPseudonym).
 *
 * The backfill draws one for every existing account from gen_random_uuid()'s
 * random bytes (the first five bytes of a v4 uuid are all random: 40 bits,
 * eight characters of five bits), drawing again on a clash, then the column
 * becomes NOT NULL and unique. A CHECK keeps any writer to the format.
 *
 * @see docs/specs/account-and-auth.md §9
 */
final class Version20260930163712 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'users.pseudonym: a random rider# pseudonym stored once per account, backfilled, NOT NULL and unique';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD pseudonym VARCHAR(8) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                alphabet CONSTANT text := '0123456789abcdefghjkmnpqrstvwxyz';
                u record;
                b bytea;
                bits bigint;
                p text;
            BEGIN
                FOR u IN SELECT id FROM users WHERE pseudonym IS NULL ORDER BY id LOOP
                    LOOP
                        b := uuid_send(gen_random_uuid());
                        bits := 0;
                        FOR i IN 0..4 LOOP
                            bits := (bits << 8) | get_byte(b, i);
                        END LOOP;
                        p := '';
                        FOR i IN 0..7 LOOP
                            p := p || substr(alphabet, ((bits >> (35 - 5 * i)) & 31)::int + 1, 1);
                        END LOOP;
                        EXIT WHEN NOT EXISTS (SELECT 1 FROM users WHERE pseudonym = p);
                    END LOOP;
                    UPDATE users SET pseudonym = p WHERE id = u.id;
                END LOOP;
            END
            $$
            SQL);
        $this->addSql('ALTER TABLE users ALTER pseudonym SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_pseudonym ON users (pseudonym)');
        $this->addSql("ALTER TABLE users ADD CONSTRAINT chk_users_pseudonym CHECK (pseudonym ~ '^[0-9a-hjkmnp-tv-z]{8}$')");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT chk_users_pseudonym');
        $this->addSql('DROP INDEX uniq_users_pseudonym');
        $this->addSql('ALTER TABLE users DROP pseudonym');
    }
}
