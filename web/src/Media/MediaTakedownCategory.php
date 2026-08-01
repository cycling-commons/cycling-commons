<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * What a third-party reporter says is wrong (docs/specs/photo-uploads.md §6c).
 *
 * The category is load-bearing twice: it drives the one narrow auto-withhold
 * exception (Urgent — intimate imagery or a child), and it is the unit of
 * finality: one decided report per photo per category is final, so a stream
 * of fresh identical reports cannot keep a photo down.
 *
 * @api Written by MediaTakedownService, read by the report form and the desk.
 */
final class MediaTakedownCategory
{
    /** "I am identifiable in this photo." */
    public const string IdentifiableSelf = 'identifiable_self';
    /** "Someone else is identifiable and has not agreed." */
    public const string IdentifiableOther = 'identifiable_other';
    /**
     * Intimate imagery, or a child is depicted. The only category that
     * withholds before a curator looks: the cost of leaving it up for a day
     * dwarfs the cost of a wrongful removal. Rate-limited harder than the
     * rest, and every use is logged — abusing it is itself a moderation
     * matter.
     */
    public const string IntimateOrChild = 'intimate_or_child';
    /** "It shows private property or something that should not be public." */
    public const string PrivateProperty = 'private_property';
    public const string Other = 'other';

    /** @return list<string> form order — the urgent category sits third, not first, so it is a deliberate choice rather than the default */
    public static function all(): array
    {
        return [
            self::IdentifiableSelf,
            self::IdentifiableOther,
            self::IntimateOrChild,
            self::PrivateProperty,
            self::Other,
        ];
    }

    public static function isValid(string $category): bool
    {
        return \in_array($category, self::all(), true);
    }

    public static function autoWithholds(string $category): bool
    {
        return self::IntimateOrChild === $category;
    }
}
