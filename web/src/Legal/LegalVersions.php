<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

/**
 * The published versions of one legal page, newest first: number, the date it
 * was published, the date it applies, whether it is significant, and what
 * changed (translation keys in the page's own domain).
 *
 * A significant version (a new purpose, recipient or country for personal
 * data; a new rule or a changed right in the terms) is emailed to every account
 * at least NOTICE_DAYS days before it applies, so a person who disagrees can
 * close their account in time (DSA Art. 14(2), GDPR Arts. 12-13). The rule
 * holds from version NOTICE_RULE_FROM on; earlier versions came before it
 * (owner 2026-10-09). A smaller change, such as clearer wording, applies on
 * the day it is published. Every version shows a bar to a signed-in reader
 * until they open the page.
 *
 * The rule covers what the terms include by reference too: the contributor
 * terms, the trademark policy and the licences page with the licence texts it
 * links. Each is pinned by hash to the terms version that last accepted it
 * (TermsIncludedTexts); a significant change to one needs a significant terms
 * version announced NOTICE_DAYS days ahead, and an editorial one is re-pinned
 * with a written reason. TermsIncludedTextsTest fails until one of the two is
 * done.
 *
 * @see docs/specs/privacy-notice.md, docs/specs/translations.md §6.2
 *
 * @api
 */
abstract class LegalVersions
{
    public const int NOTICE_DAYS = 30;
    public const int NOTICE_RULE_FROM = 3;
    /** The page these versions belong to: its route, its translation domain and its file name. */
    public const string PAGE = '';

    /** @return list<array{number: int, date: string, effective: string, significant: bool, changes: list<string>}> */
    abstract protected static function entries(): array;

    /** @return list<array{number: int, date: string, effective: string, significant: bool, changes: list<string>}> */
    public static function all(): array
    {
        return static::entries();
    }

    /**
     * The newest version, announced or applying.
     *
     * @return array{number: int, date: string, effective: string, significant: bool, changes: list<string>}
     */
    public static function latest(): array
    {
        return static::entries()[0];
    }

    /**
     * The version that applies on `$today`.
     *
     * @return array{number: int, date: string, effective: string, significant: bool, changes: list<string>}
     */
    public static function current(?\DateTimeImmutable $today = null): array
    {
        $day = ($today ?? new \DateTimeImmutable())->format('Y-m-d');
        foreach (static::entries() as $v) {
            if ($v['effective'] <= $day) {
                return $v;
            }
        }

        $entries = static::entries();

        return $entries[array_key_last($entries)];
    }

    /**
     * The version announced but not applying yet on `$today`, if any.
     *
     * @return array{number: int, date: string, effective: string, significant: bool, changes: list<string>}|null
     */
    public static function upcoming(?\DateTimeImmutable $today = null): ?array
    {
        $latest = static::latest();

        return $latest['effective'] > ($today ?? new \DateTimeImmutable())->format('Y-m-d') ? $latest : null;
    }
}
