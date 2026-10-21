<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `submission.lang`: the language a contribution is written in
 * (moderation-and-contribution.md §3.1d). A town or region text keeps the
 * language it names; every other submission from before is taken to be
 * English, the language assumed when none is given.
 */
final class Version20261020040000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'submission.lang: the language a contribution is written in, en when none was given';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE submission ADD lang VARCHAR(2) DEFAULT 'en' NOT NULL");
        $this->addSql("COMMENT ON COLUMN submission.lang IS 'Language the contribution is written in: a text''s own, the page''s for the site''s forms, a partner app''s lang; en when none was given.'");
        $this->addSql("UPDATE submission SET lang = payload->>'lang' WHERE type = 'text' AND payload->>'lang' ~ '^[a-z]{2}$'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE submission DROP lang');
    }
}
