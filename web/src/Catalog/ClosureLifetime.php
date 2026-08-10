<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * How long a reported closure stays true.
 *
 * A "road closed" that never expires becomes a lie (Manifesto §VII), and
 * wiki/data-priority.md has promised decay — "auto-stale after N days unless
 * re-confirmed" — since before anything implemented it. This is the first
 * implementation, and it is deliberately the narrow case: closures, where the
 * reporter told us how long they expected it to last, so the window is a
 * stated fact rather than a constant somebody guessed.
 *
 * The vocabulary is Scout's `CLOSED FOR?` menu (docs/TODO.md, the owner's
 * Garmin Store description), so a tag tapped on a bike computer and a closure
 * typed into the improve form decay by the same rule.
 *
 * Nothing here deletes. Expiry retires an item, and `ItemState::Retired` is
 * already outside `servedSqlTuple()`, so the closure simply stops rendering
 * while the row, its history and its confirmations all stay. A closure that
 * was true in March is still a true record of March, and it is what makes a
 * repeat closure legible next year.
 *
 * @api Used by ClosureExpiryService and the F registry.
 */
final class ClosureLifetime
{
    /** The `hazardType` value this applies to; every other hazard is untouched. */
    public const string CLOSED_TYPE = 'Road closed';

    /** The attribute the reporter fills in, and Scout writes. */
    public const string FIELD = 'closedFor';

    /**
     * Days each answer is worth, from the moment it was last observed.
     *
     * 'Unknown' is the one answer with no duration in it, and it must not
     * quietly mean "forever" — that is precisely the lie §VII names. It gets
     * the longest bounded window instead, after which the closure retires and
     * has to be re-reported or re-confirmed like any other.
     */
    public const array DAYS = [
        'Today' => 2,
        'Days' => 10,
        'Weeks' => 42,
        'Months' => 180,
        'Unknown' => 180,
    ];

    /** Form choices, in the order a rider reads them. */
    public const array CHOICES = ['Unknown', 'Today', 'Days', 'Weeks', 'Months'];

    /**
     * True when this item is the kind of thing that expires at all.
     *
     * @param array<string, mixed> $attributes
     */
    public static function applies(string $letter, array $attributes): bool
    {
        return 'F' === $letter
            && self::CLOSED_TYPE === ($attributes['hazardType'] ?? null);
    }

    /**
     * When a closure observed at $observedAt stops being trustworthy.
     *
     * An unrecognised or absent answer is treated as 'Unknown' rather than
     * being skipped: a closure with no stated duration is exactly the row that
     * would otherwise sit on the map forever.
     *
     * @param array<string, mixed> $attributes
     */
    public static function expiresAt(array $attributes, \DateTimeImmutable $observedAt): \DateTimeImmutable
    {
        $answer = $attributes[self::FIELD] ?? null;
        $days = (\is_string($answer) && isset(self::DAYS[$answer]))
            ? self::DAYS[$answer]
            : self::DAYS['Unknown'];

        return $observedAt->modify(sprintf('+%d days', $days));
    }
}
