<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Scout;

use App\Catalog\ItemType;

/**
 * The Scout tag vocabulary, and the only thing the server accepts from a ride.
 *
 * Scout writes six tag types (plus an automatic overtake count) into the
 * activity file a bike computer was already recording. The **file never reaches
 * us**: it is decoded in the rider's own browser, and what is posted is a list
 * of these — a position, a type, a moment. That is the trust boundary
 * (`Dated/2026-08-09-scout-cc-tagger-plan.md` §1), and it is enforced in
 * ScoutIntakeController rather than merely described here.
 *
 * The mapping to catalog letters is deliberately not one-to-one. `resupply`
 * covers water, food and a repair stop, which are three letters here; `other`
 * covers nothing at all until the rider says what it was. So a tag arrives as a
 * *proposal* the rider resolves in the review screen, and the letter is what
 * they choose there — never inferred silently, because a mis-filed tag is worse
 * than an unfiled one: nobody looks for it again.
 *
 * @api Read by ScoutIntakeController and the review screen's wire contract.
 */
final class ScoutTag
{
    /** @var list<string> Every tag type Scout can write. */
    public const array TYPES = ['resupply', 'closure', 'surface', 'notice', 'scenery', 'other'];

    /**
     * @var array<string, list<string>> tag type → the catalog letters it may
     *                                  become. A single-entry list is a
     *                                  default, never a lock: the rider can
     *                                  always pick another letter in review.
     */
    public const array LETTERS = [
        'resupply' => ['C', 'D'],       // water & food, or a bike service
        'closure' => ['F'],             // hazards & conditions
        'surface' => ['A'],             // the one segment-located letter
        'notice' => ['F'],
        'scenery' => ['I'],
        'other' => ['C', 'D', 'F', 'H', 'I', 'J'],
    ];

    /**
     * The letters a tag of this type may legitimately become.
     *
     * @return list<string>
     */
    public static function lettersFor(string $type): array
    {
        return self::LETTERS[$type] ?? [];
    }

    /** Is this letter a legitimate resolution of this tag type? */
    public static function allows(string $type, string $letter): bool
    {
        return \in_array($letter, self::lettersFor($type), true);
    }

    /** The ItemType for a letter, or null when the letter is not one of ours. */
    public static function itemTypeFor(string $letter): ?ItemType
    {
        foreach (ItemType::cases() as $type) {
            if ($type->letter() === $letter) {
                return $type;
            }
        }

        return null;
    }
}
