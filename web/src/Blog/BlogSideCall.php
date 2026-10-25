<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Blog;

/**
 * What the invitation beside a post asks for.
 *
 * Most posts point to the post that lists the ways to help build Cycling
 * Commons ({@see self::HELP_SLUG}). A post that speaks to every rider asks
 * them to add what they know instead. The help post itself always does: it
 * never points at itself, and neither does a site where that post is not
 * published yet.
 *
 * @see docs/specs/blog.md §5
 *
 * @api
 */
enum BlogSideCall: string
{
    case Help = 'help';
    case Contribute = 'contribute';

    /** The English help post; a reader of another language gets its translation. */
    public const string HELP_SLUG = 'help-build-cycling-commons';

    /** @return array<string, self> label => case, for the admin choice */
    public static function choices(): array
    {
        return [
            'Help build Cycling Commons: links the help post' => self::Help,
            'Add what you know: links the contribute page' => self::Contribute,
        ];
    }
}
