<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Support\ReportGround;

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

    /**
     * A category this service will store against an upload.
     *
     * Since 2026-08-30 that is either one of the five below, which is what the
     * old photo form sent, or a `ReportGround` value, which is what the shared
     * report route sends. The column holds a vocabulary, not an enum, and the
     * merge widened the vocabulary rather than mapping one onto the other:
     * `advertising` has no equivalent among the five, and inventing one would
     * make the desk say something the reporter did not.
     *
     * `intimate_or_child` is deliberately spelled the same in both, which is
     * why the urgent path needed no mapping at all.
     *
     * @see docs/specs/content-reports.md §4
     */
    public static function isValid(string $category): bool
    {
        if (\in_array($category, self::all(), true)) {
            return true;
        }

        return null !== ReportGround::tryFrom($category);
    }

    /**
     * The catalogue key a desk shows for a stored category.
     *
     * A `ReportGround` value reads the way the Reports desk reads it, which
     * covers `intimate_or_child` and `private_property` too, since both
     * vocabularies spell them the same. The three values only the old photo
     * form stored keep their `media.report.category.*` keys, so an old row
     * still reads as what the reporter picked.
     */
    public static function label(string $category): string
    {
        return ReportGround::tryFrom($category)?->label() ?? 'media.report.category.'.$category;
    }

    public static function autoWithholds(string $category): bool
    {
        return self::IntimateOrChild === $category;
    }
}
