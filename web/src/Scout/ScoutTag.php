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
     * @var array<string, array<int, list<string>>> tag type → the device's
     *                                              sub-menu value → the letters that sub-menu value may become, best
     *                                              first.
     *
     * The sub-menu is the half of a Scout tag that says what the rider actually
     * meant, and it does not always land where the tag type alone would put it:
     * SCENERY · HISTORY and SCENERY · ARCHITECTURE are J (history & culture),
     * not I (scenic views), and offering only I made the rider re-file their own
     * answer (owner-reported 2026-08-12).
     *
     * Values are Scout's own `poi_detail` numbers (fit-viewer.html's POI_*
     * tables). An unlisted value falls back to LETTERS, which is the coarse
     * answer rather than a wrong one.
     */
    public const array DETAIL_LETTERS = [
        // NOTICE?  POTHOLES · CROSSING · CORNER · OTHER · UNKNOWN
        'notice' => [1 => ['F'], 2 => ['F'], 3 => ['F'], 4 => ['F'], 5 => ['F']],
        // CLOSED FOR?  TODAY · DAYS · WEEKS · MONTHS · UNKNOWN
        'closure' => [1 => ['F'], 2 => ['F'], 3 => ['F'], 4 => ['F'], 5 => ['F']],
        // SCENERY?  NATURE · HISTORY · CULTURE · VIEW · ARCHITECTURE · UNKNOWN
        'scenery' => [
            1 => ['I'], 2 => ['J', 'I'], 3 => ['J', 'I'],
            4 => ['I'], 5 => ['J', 'I'], 6 => ['I', 'J'],
        ],
        // WHAT KIND?  WATER · FOOD · REPAIR
        'resupply' => [1 => ['C'], 2 => ['C'], 3 => ['D']],
        // The surface submenu is the surface itself; it is always A.
        'surface' => [],
    ];

    /**
     * @var array<string, array<int, array<string, string>>> tag type →
     *                                                       sub-menu value → attribute values that sub-menu value states
     *
     * What the rider chose on the device, in the vocabulary the form uses. This
     * is not an assumption: they picked it, on the road, at the place. Filling
     * it in is the difference between a Scout tag and a pin somebody has to
     * describe from memory a week later.
     */
    public const array DETAIL_FIELDS = [
        'notice' => [
            1 => ['hazardType' => 'Potholes'],
            2 => ['hazardType' => 'Junction / crossing'],
            3 => ['hazardType' => 'Bad corner'],
            4 => ['hazardType' => 'Other'],
        ],
        // A closure is a road-closed hazard that knows its own duration, which
        // is what lets the map retire it by itself (ClosureLifetime).
        'closure' => [
            1 => ['hazardType' => 'Road closed', 'closedFor' => 'Today'],
            2 => ['hazardType' => 'Road closed', 'closedFor' => 'Days'],
            3 => ['hazardType' => 'Road closed', 'closedFor' => 'Weeks'],
            4 => ['hazardType' => 'Road closed', 'closedFor' => 'Months'],
            5 => ['hazardType' => 'Road closed', 'closedFor' => 'Unknown'],
        ],
    ];

    /**
     * The letters a tag may legitimately become — narrowed by the device's
     * sub-menu value when there is one.
     *
     * @return list<string>
     */
    public static function lettersFor(string $type, ?int $detail = null): array
    {
        $byDetail = self::DETAIL_LETTERS[$type][$detail] ?? null;

        return $byDetail ?? (self::LETTERS[$type] ?? []);
    }

    /**
     * Attribute values the device's sub-menu already answered.
     *
     * @return array<string, string>
     */
    public static function fieldsFor(string $type, ?int $detail): array
    {
        return self::DETAIL_FIELDS[$type][$detail] ?? [];
    }

    /**
     * @var list<string> Every letter a Scout tag may be re-filed onto.
     *
     * A mis-tap at 30 km/h is the normal case, not the exception: the menu is
     * six tiles on a bike computer and the rider is riding. Whatever they meant,
     * they know it now — so review must let them move a tag ANYWHERE the tag
     * could have gone, not only within the type they hit by accident (owner:
     * "I now can't change from scenic view to another category", 2026-08-12).
     *
     * `A` is not in the list. Road surface is the one segment-located letter: it
     * needs a start and an end, and a single tapped point cannot become one. A
     * surface tag reaches A through its own pairing path.
     */
    public const array REFILE_LETTERS = ['C', 'D', 'F', 'H', 'I', 'J'];

    /**
     * Is this letter a legitimate resolution of this tag type?
     *
     * Narrowing (DETAIL_LETTERS) decides what is offered FIRST; it never decides
     * what is allowed. The rider is correcting their own tag, and a form that
     * refuses the correction is worse than one that guessed wrong to begin with.
     *
     * $type and $detail are deliberately part of the signature and deliberately
     * unread: this is the intake contract ("may THIS tag refile to THAT
     * letter?"), and today's rule happens not to vary by them. Callers must not
     * have to know that.
     *
     * @psalm-suppress UnusedParam
     */
    public static function allows(string $type, string $letter, ?int $detail = null): bool
    {
        // A is not reachable from here at all — see offerFor().
        return \in_array($letter, self::REFILE_LETTERS, true);
    }

    /**
     * The letters to OFFER, best first: what the sub-menu implies, then
     * everything else a tag can be re-filed onto.
     *
     * @return list<string>
     */
    public static function offerFor(string $type, ?int $detail = null): array
    {
        /* A is deliberately absent from every offer for now: the intake cannot
           store a segment from a single tapped point, so offering it would end
           in a refusal at the last step. A surface tag still LISTS — the rider
           may have meant a viewpoint — it simply cannot become road surface
           here yet (plan task 6). */
        $best = array_values(array_filter(
            self::lettersFor($type, $detail),
            static fn (string $l): bool => 'A' !== $l,
        ));
        $rest = array_values(array_diff(self::REFILE_LETTERS, $best));

        return [...$best, ...$rest];
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
