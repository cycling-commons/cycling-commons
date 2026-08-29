<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Blog;

/**
 * Draft or published, and nothing in between.
 *
 * Two, on purpose. A post is a page a stranger reads cold, so the only
 * question that matters is whether it is finished. Scheduling, review states
 * and expiry are all real features and all of them are somebody's second
 * problem; this is the first.
 *
 * @see docs/specs/blog.md §2
 *
 * @api
 */
enum BlogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return 'blog.status.'.$this->value;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? self::Draft;
    }
}
