<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Blog;

/**
 * Which languages the blog is written in.
 *
 * **Two, and not the site's five** (owner, 2026-08-29). The interface is
 * translated by a workflow that reviews one string at a time; a blog post is
 * eight hundred words of voice, and running that through the same workflow
 * five times per post is the cost that quietly stops anybody writing the
 * second one.
 *
 * A reader in French sees the English posts rather than an empty page, and the
 * index says which language it is showing. That is a smaller lie than a blog
 * nobody updates.
 *
 * Widening this is a one-line change plus the copy to fill it, which is the
 * right shape: the constraint is people, not code.
 *
 * @see docs/specs/blog.md §3
 *
 * @api
 */
final class BlogLocales
{
    /** @var list<string> */
    public const array WRITTEN = ['en', 'nl'];

    /** What a reader gets when their own language has nothing. */
    public const string FALLBACK = 'en';

    public static function isWritten(string $locale): bool
    {
        return \in_array($locale, self::WRITTEN, true);
    }

    /** The locale to actually query for, given the one the reader is browsing in. */
    public static function resolve(string $locale): string
    {
        return self::isWritten($locale) ? $locale : self::FALLBACK;
    }
}
