<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Blog;

use App\Blog\Entity\BlogPost;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every read the blog needs.
 *
 * **Published means published.** Every public query filters on
 * {@see BlogStatus::Published} AND a non-null `published_at`, together. A row
 * can only be in one of those states by hand, and if it ever is, the safe
 * reading is "not ready".
 *
 * @see docs/specs/blog.md §4
 *
 * @api
 */
final class BlogRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * The index, newest first.
     *
     * @return list<BlogPost>
     */
    public function published(string $locale, int $limit, int $offset = 0): array
    {
        /** @var list<BlogPost> $rows */
        $rows = $this->liveQuery($locale)
            ->orderBy('p.publishedAt', 'DESC')
            // A tie-break, because two posts published the same day would
            // otherwise swap places between page loads.
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countPublished(string $locale): int
    {
        return (int) $this->liveQuery($locale)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** One post by its slug, in the language it was written in. Null when it is a draft. */
    public function publishedBySlug(string $slug, string $locale): ?BlogPost
    {
        $post = $this->liveQuery($locale)
            ->andWhere('p.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $post instanceof BlogPost ? $post : null;
    }

    /**
     * The same post in another language, if somebody wrote one.
     *
     * Looks both ways: a post is either the original that others point at, or
     * one of the ones pointing. A reader does not know or care which they
     * landed on.
     *
     * @return list<BlogPost>
     */
    public function siblings(BlogPost $post): array
    {
        $originalId = $post->getTranslationOf() ?? $post->getId();
        if (null === $originalId) {
            return [];
        }

        /** @var list<BlogPost> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p')
            ->from(BlogPost::class, 'p')
            ->where('p.status = :live')
            ->andWhere('p.publishedAt IS NOT NULL')
            ->andWhere('p.id != :self')
            ->andWhere('p.id = :original OR p.translationOf = :original')
            ->setParameter('live', BlogStatus::Published)
            ->setParameter('self', $post->getId())
            ->setParameter('original', $originalId)
            ->orderBy('p.locale', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The other posts, for the sidebar on a post page.
     *
     * Excludes the one being read: a list of "other posts" that includes the
     * page you are on wastes the top slot, which is the only one most people
     * look at.
     *
     * @return list<BlogPost>
     */
    public function othersThan(BlogPost $post, string $locale, int $limit = 8): array
    {
        /** @var list<BlogPost> $rows */
        $rows = $this->liveQuery($locale)
            ->andWhere('p.id != :self')
            ->setParameter('self', $post->getId())
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The most recent handful, for the feed.
     *
     * @return list<BlogPost>
     */
    public function forFeed(string $locale, int $limit = 20): array
    {
        return $this->published($locale, $limit);
    }

    private function liveQuery(string $locale): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(BlogPost::class, 'p')
            ->where('p.locale = :locale')
            ->andWhere('p.status = :live')
            // Belt and braces with the status: either alone would let a
            // half-set row onto a public page, and "not ready" is the safe
            // reading of a contradiction.
            ->andWhere('p.publishedAt IS NOT NULL')
            ->setParameter('locale', $locale)
            ->setParameter('live', BlogStatus::Published);
    }
}
