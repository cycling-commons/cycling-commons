<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Scout;

use App\Catalog\ItemType;

/**
 * Scout tag vocabulary. Letter is chosen in review, never inferred
 * (docs/specs/moderation-and-contribution.md (Scout intake)).
 *
 * @api
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
        'resupply' => ['B', 'D'],       // water & food, or a bike service
        'closure' => ['E'],             // hazards & conditions
        'surface' => ['A'],             // the one segment-located letter
        'notice' => ['E'],
        'scenery' => ['P'],
        'other' => ['B', 'D', 'E', 'G', 'P', 'Q'],
    ];

    /**
     * @var array<string, array<int, list<string>>> tag type → poi_detail → letters, best first
     */
    public const array DETAIL_LETTERS = [
        // NOTICE?  POTHOLES · CROSSING · CORNER · OTHER · UNKNOWN
        'notice' => [1 => ['E'], 2 => ['E'], 3 => ['E'], 4 => ['E'], 5 => ['E']],
        // CLOSED FOR?  TODAY · DAYS · WEEKS · MONTHS · UNKNOWN
        'closure' => [1 => ['E'], 2 => ['E'], 3 => ['E'], 4 => ['E'], 5 => ['E']],
        // SCENERY?  NATURE · HISTORY · CULTURE · VIEW · ARCHITECTURE · UNKNOWN
        // One pick, one home (owner 2026-09-07); UNKNOWN offers both.
        'scenery' => [
            1 => ['P'], 2 => ['Q'], 3 => ['Q'],
            4 => ['P'], 5 => ['Q'], 6 => ['P', 'Q'],
        ],
        // WHAT KIND?  WATER · FOOD · REPAIR
        'resupply' => [1 => ['B'], 2 => ['B'], 3 => ['D']],
        // The surface submenu is the surface itself; it is always A.
        'surface' => [],
    ];

    /**
     * @var array<string, array<int, array<string, string>>> tag type → poi_detail → form attributes
     */
    public const array DETAIL_FIELDS = [
        'notice' => [
            1 => ['hazardType' => 'Potholes'],
            2 => ['hazardType' => 'Junction / crossing'],
            3 => ['hazardType' => 'Bad corner'],
            4 => ['hazardType' => 'Other'],
        ],
        // The pick IS the Type on its home letter; a re-filed tag carries none.
        'scenery' => [
            1 => ['type' => 'Natural feature'],
            2 => ['type' => 'Heritage site'],
            3 => ['type' => 'Museum / culture'],
            4 => ['type' => 'Viewpoint / high point'],
            5 => ['type' => 'Architecture'],
        ],
        // Road-closed + duration so ClosureLifetime can retire it.
        'closure' => [
            1 => ['hazardType' => 'Road closed', 'closedFor' => 'Today'],
            2 => ['hazardType' => 'Road closed', 'closedFor' => 'Days'],
            3 => ['hazardType' => 'Road closed', 'closedFor' => 'Weeks'],
            4 => ['hazardType' => 'Road closed', 'closedFor' => 'Months'],
            5 => ['hazardType' => 'Road closed', 'closedFor' => 'Unknown'],
        ],
    ];

    /** @return list<string> */
    public static function lettersFor(string $type, ?int $detail = null): array
    {
        $byDetail = self::DETAIL_LETTERS[$type][$detail] ?? null;

        return $byDetail ?? (self::LETTERS[$type] ?? []);
    }

    /** @return array<string, string> */
    public static function fieldsFor(string $type, ?int $detail): array
    {
        return self::DETAIL_FIELDS[$type][$detail] ?? [];
    }

    /** @var list<string> Point-tag refile letters; A is stretch-only (docs/specs/moderation-and-contribution.md (Scout intake)). */
    public const array REFILE_LETTERS = ['B', 'D', 'E', 'G', 'P', 'Q'];

    /**
     * Narrowing decides offer order, not what is allowed.
     *
     * @psalm-suppress UnusedParam
     */
    public static function allows(string $type, string $letter, ?int $detail = null): bool
    {
        // A is stretch-only (docs/specs/moderation-and-contribution.md (Scout intake)).
        if ('A' === $letter) {
            return 'surface' === $type;
        }

        return \in_array($letter, self::REFILE_LETTERS, true);
    }

    /** @return list<string> */
    public static function offerFor(string $type, ?int $detail = null): array
    {
        // Point-tag dropdown never offers A; stretches take a separate path.
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
