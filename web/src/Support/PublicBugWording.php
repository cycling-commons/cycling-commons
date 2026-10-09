<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a bug's public wording names its reporter (rulebook RB-BUG-07).
 *
 * The public title and text appear on /known-issues and go to GitHub as they
 * are. The reporter's address is always refused. Their account name is
 * matched as a whole word, ignoring case, and only from three letters on; a
 * display name can be an ordinary word ("Map", "Road"), so a name match asks
 * the curator to confirm once rather than refusing for good. Other names it
 * cannot know; the rule covers them.
 *
 * @see docs/specs/contact-and-support.md §9
 */
final class PublicBugWording
{
    public const string ADDRESS = 'address';
    public const string NAME = 'name';

    /** @return self::ADDRESS|self::NAME|null */
    public static function check(string $text, ?string $email, ?string $name): ?string
    {
        $email = trim((string) $email);
        if ('' !== $email && false !== mb_stripos($text, $email)) {
            return self::ADDRESS;
        }
        $name = trim((string) $name);
        if (mb_strlen($name) >= 3 && 1 === preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text)) {
            return self::NAME;
        }

        return null;
    }
}
