<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `blog_post`: the smallest blog that is honest.
 *
 * **Why a table and not files in git.** A post is written by whoever runs the
 * community, not by whoever can open a pull request, and the whole reason the
 * blog exists is that somebody who is not a developer has to be able to write
 * one.
 *
 * **Locale is a column, not a separate table.** English and Dutch to start, and
 * a Dutch post is its own post rather than a translation of an English one:
 * pairing them would mean a Dutch-only post could not exist, and it will.
 * `translation_of` links the pair when there is one, so a reader on a Dutch
 * page can be offered the English original and not the whole index.
 *
 * @see docs/specs/blog.md
 */
final class Version20260829090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'blog_post: posts, drafts and their locale.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE blog_post (
                id BIGSERIAL PRIMARY KEY,
                slug VARCHAR(160) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title VARCHAR(200) NOT NULL,
                lede TEXT DEFAULT NULL,
                body TEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                author_id BIGINT DEFAULT NULL,
                translation_of BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);

        // One slug per locale: /blog/why-a-commons in English and the Dutch
        // post about the same thing may share a slug or not, and neither is
        // wrong.
        $this->addSql('CREATE UNIQUE INDEX uniq_blog_post_slug_locale ON blog_post (slug, locale)');
        // The index page's only query: published, this locale, newest first.
        $this->addSql('CREATE INDEX idx_blog_post_live ON blog_post (locale, status, published_at DESC)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE blog_post');
    }
}
