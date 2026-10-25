<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `blog_post.side_call`: what the invitation beside a post asks for
 * (App\Blog\BlogSideCall). Every post starts on `help`.
 */
final class Version20261025040000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'blog_post.side_call: the invitation beside a post';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE blog_post ADD side_call VARCHAR(16) DEFAULT 'help' NOT NULL");
        $this->addSql("COMMENT ON COLUMN blog_post.side_call IS 'What the invitation beside the post asks for (App\\\\Blog\\\\BlogSideCall): help links the help post, contribute links the contribute page.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE blog_post DROP side_call');
    }
}
