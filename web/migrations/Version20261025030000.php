<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `blog_post.illustration`: the drawing a post opens with, by name
 * (App\Blog\BlogIllustrations). A drawing in the repository, not an upload.
 */
final class Version20261025030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'blog_post.illustration: the drawing a post opens with';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blog_post ADD illustration VARCHAR(40) DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN blog_post.illustration IS 'The drawing the post opens with, by name (App\\\\Blog\\\\BlogIllustrations); NULL is none.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blog_post DROP illustration');
    }
}
