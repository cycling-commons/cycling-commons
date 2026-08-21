<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Third-party report category. Drives auto-withhold and per-photo finality.
 *
 * @see docs/specs/photo-uploads.md §6c
 *
 * @api
 */
final class MediaTakedownCategory
{
    /** "I am identifiable in this photo." */
    public const string IdentifiableSelf = 'identifiable_self';
    /** "Someone else is identifiable and has not agreed." */
    public const string IdentifiableOther = 'identifiable_other';
    /** Intimate imagery or a child: the only auto-withhold category. @see docs/specs/photo-uploads.md §6c */
    public const string IntimateOrChild = 'intimate_or_child';
    /** "It shows private property or something that should not be public." */
    public const string PrivateProperty = 'private_property';
    public const string Other = 'other';

    /** @return list<string> form order — urgent sits third so it is not the default */
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
