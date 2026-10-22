<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `submission.code`: eight random characters a rider and a curator read and
 * quote (`#k7m2x9qa`), in place of the running number. Crockford base32 in
 * lower case without i, l, o and u, the rider handle's alphabet, so 32^8
 * codes and nothing to count from. `submission_new_code()` draws one that no
 * row holds yet; a BEFORE INSERT trigger calls it, so every insert gets one,
 * Doctrine's and raw SQL's alike. Every stored row gets one now.
 */
final class Version20261022040000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'submission.code: a random eight-character code drawn on insert, backfilled, NOT NULL and unique';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE submission ADD code VARCHAR(8) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION submission_new_code() RETURNS text LANGUAGE plpgsql VOLATILE AS $$
            DECLARE
                alphabet CONSTANT text := '0123456789abcdefghjkmnpqrstvwxyz';
                b bytea;
                bits bigint;
                p text;
            BEGIN
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
                    EXIT WHEN NOT EXISTS (SELECT 1 FROM submission WHERE code = p);
                END LOOP;
                RETURN p;
            END
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION submission_code() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.code IS NULL THEN
                    NEW.code := submission_new_code();
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER submission_code BEFORE INSERT ON submission FOR EACH ROW EXECUTE FUNCTION submission_code()');
        // One row at a time, so each draw sees the codes drawn before it.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                s record;
            BEGIN
                FOR s IN SELECT id FROM submission WHERE code IS NULL ORDER BY id LOOP
                    UPDATE submission SET code = submission_new_code() WHERE id = s.id;
                END LOOP;
            END
            $$
            SQL);
        $this->addSql('ALTER TABLE submission ALTER code SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_submission_code ON submission (code)');
        $this->addSql("ALTER TABLE submission ADD CONSTRAINT chk_submission_code CHECK (code ~ '^[0-9a-hjkmnp-tv-z]{8}$')");
        $this->addSql("COMMENT ON COLUMN submission.code IS 'The code people read and quote (#k7m2x9qa): eight random Crockford base32 characters, drawn on insert by the submission_code trigger.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER submission_code ON submission');
        $this->addSql('DROP FUNCTION submission_code()');
        $this->addSql('DROP FUNCTION submission_new_code()');
        $this->addSql('ALTER TABLE submission DROP CONSTRAINT chk_submission_code');
        $this->addSql('DROP INDEX uniq_submission_code');
        $this->addSql('ALTER TABLE submission DROP code');
    }
}
