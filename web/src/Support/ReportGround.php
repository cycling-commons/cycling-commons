<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

/**
 * Why somebody is reporting it.
 *
 * The same five grounds `/terms` §12 already publishes as "what gets taken
 * down", plus the sixth added on 2026-08-28 for generated images. They are the
 * same list on purpose: a reporter should be choosing from the rules we
 * actually apply, and the statement of reasons DSA Article 17 requires has to
 * name a ground the author can go and read.
 *
 * @see docs/specs/content-reports.md §2
 *
 * @api
 */
enum ReportGround: string
{
    case Unlawful = 'unlawful';
    case PersonalData = 'personal_data';
    case Untrue = 'untrue';
    case Abuse = 'abuse';
    case Advertising = 'advertising';
    case Generated = 'generated';

    /** The catalogue key. Mirrors the `terms.mod_std*` line it comes from. */
    public function label(): string
    {
        return 'report.ground.'.$this->value;
    }

    /**
     * Grounds that are a legal claim rather than a quality judgement.
     *
     * These get a curator's attention first, and their decisions are the ones
     * most likely to be appealed, so the desk sorts on it.
     */
    public function isLegal(): bool
    {
        return match ($this) {
            self::Unlawful, self::PersonalData => true,
            default => false,
        };
    }

    /**
     * The legal-claim grounds as a list, for the desk's IN() sort.
     *
     * @return list<self>
     */
    public static function legal(): array
    {
        return array_values(array_filter(self::all(), static fn (self $g): bool => $g->isLegal()));
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
