<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Blog\Entity;

use App\Blog\BlogStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One post.
 *
 * **The body is the same restricted markdown the bug desk uses**
 * ({@see \App\Support\BugMarkdown}). Not a decision made to save work: it means
 * no second sanitiser profile, no second set of allowed tags, and no second
 * place to get wrong. It also means a post cannot embed an image or a link,
 * which for a first blog is a feature: the posts that matter are prose.
 *
 * **A Dutch post is a post, not a translation.** Locale is a column and each
 * row stands alone, so a Dutch-only post is possible and normal.
 * {@see $translationOf} links a pair when one exists, which lets a reader be
 * offered the other language rather than the whole index.
 *
 * @see docs/specs/blog.md §2
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_post')]
#[ORM\UniqueConstraint(name: 'uniq_blog_post_slug_locale', columns: ['slug', 'locale'])]
class BlogPost
{
    public const int TITLE_MAX = 200;
    public const int LEDE_MAX = 400;
    public const int BODY_MAX = 20000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 200)]
    private string $title;

    /** The standfirst. Optional: a post that does not need one should not have one. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lede = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 16, enumType: BlogStatus::class)]
    private BlogStatus $status = BlogStatus::Draft;

    /**
     * Set when it is first published, and kept if it is later withdrawn.
     *
     * Kept rather than cleared so that un-publishing and re-publishing does not
     * silently move a post to the top of the index, which would misdate it for
     * anybody who read it the first time.
     */
    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** No FK: a post outlives the account that wrote it. */
    #[ORM\Column(name: 'author_id', type: Types::BIGINT, nullable: true)]
    private ?int $authorId = null;

    #[ORM\Column(name: 'translation_of', type: Types::BIGINT, nullable: true)]
    private ?int $translationOf = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $locale, string $title, string $body)
    {
        $this->slug = $slug;
        $this->locale = $locale;
        $this->title = $title;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = self::slugify($slug);
        $this->touch();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = mb_substr(trim($title), 0, self::TITLE_MAX);
        $this->touch();
    }

    public function getLede(): ?string
    {
        return $this->lede;
    }

    public function setLede(?string $lede): void
    {
        $lede = null === $lede ? null : trim($lede);
        $this->lede = null !== $lede && '' !== $lede ? mb_substr($lede, 0, self::LEDE_MAX) : null;
        $this->touch();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = mb_substr($body, 0, self::BODY_MAX);
        $this->touch();
    }

    public function getStatus(): BlogStatus
    {
        return $this->status;
    }

    /**
     * Publishing stamps the date once, the first time.
     *
     * Withdrawing keeps it, so a post that comes back does not jump to the top
     * of the index and misdate itself for everybody who already read it.
     */
    public function setStatus(BlogStatus $status, ?\DateTimeImmutable $now = null): void
    {
        if (BlogStatus::Published === $status && null === $this->publishedAt) {
            $this->publishedAt = $now ?? new \DateTimeImmutable();
        }
        $this->status = $status;
        $this->touch();
    }

    public function isPublished(): bool
    {
        return BlogStatus::Published === $this->status && null !== $this->publishedAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getAuthorId(): ?int
    {
        return $this->authorId;
    }

    public function setAuthorId(?int $id): void
    {
        $this->authorId = $id;
    }

    public function getTranslationOf(): ?int
    {
        return $this->translationOf;
    }

    public function setTranslationOf(?int $id): void
    {
        $this->translationOf = $id;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * A URL-safe slug, from a title in any of the languages this site speaks.
     *
     * Transliterates first, so "Wat is er nieuw" and "Où nous trouver" both
     * come out readable rather than as a row of hyphens.
     */
    public static function slugify(string $value): string
    {
        $value = trim($value);
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if (null !== $tr) {
                $value = (string) $tr->transliterate($value);
            }
        }

        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return mb_substr(trim($value, '-'), 0, 160);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
