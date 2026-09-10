<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * How long a reported closure stays true. Expiry retires the item; it does not delete. 'Unknown' must not mean forever.
 *
 * @see docs/specs/edit-items/E-hazards.md (Closures expire themselves)
 *
 * @api
 */
final class ClosureLifetime
{
    /** The `hazardType` this applies to; every other hazard is untouched. */
    public const string CLOSED_TYPE = 'Road closed';

    /** Attribute the reporter fills in, and Scout writes. */
    public const string FIELD = 'closedFor';

    /**
     * Days from last observation. 'Unknown' gets the longest bounded window, not unbounded.
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
     * @param array<string, mixed> $attributes
     */
    public static function applies(string $letter, array $attributes): bool
    {
        return 'E' === $letter
            && self::CLOSED_TYPE === ($attributes['hazardType'] ?? null);
    }

    /**
     * Unrecognised or absent `closedFor` is treated as 'Unknown', not skipped.
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
