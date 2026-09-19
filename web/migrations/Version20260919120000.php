<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A curator-room post has a title (owner 2026-09-19). Existing posts get
 * their first line, so nothing on the board is left nameless.
 *
 * @see docs/specs/moderation-and-contribution.md §13.3
 */
final class Version20260919120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'curator_post.title';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curator_post ADD title VARCHAR(120) DEFAULT NULL');
        $this->addSql("UPDATE curator_post SET title = LEFT(SPLIT_PART(body, E'\\n', 1), 120)");
        $this->addSql('ALTER TABLE curator_post ALTER title SET NOT NULL');
        $this->addSql("COMMENT ON COLUMN curator_post.title IS 'One line, 1 to 120 characters; the card''s heading.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curator_post DROP title');
    }
}
