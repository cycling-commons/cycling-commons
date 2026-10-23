<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * A region's figures for the top of its page: its area, its population and
 * the year that was counted, and its ride character (flat, hilly or
 * mountainous). They hold in every language, and anyone signed in proposes
 * them on the geography form (moderation-and-contribution.md §3.1g).
 *
 * `region.facts` (JSONB) holds one entry per figure that was set:
 * `{"area": {"value": 2680, "submissionId": 12, "at": "..."},
 *   "population": {"value": 494000, "year": 2024, ...},
 *   "ride": {"value": "flat", ...}}`. `submissionId` is the approved
 * proposal that set it, so a revoke puts back only what is still its own.
 *
 * The area an entry states is what the page shows. `region.area_km2` stays
 * the area measured from the boundary: lookups pick the smallest region
 * holding a point by it, and it is the page's area while nobody stated one.
 *
 * `region.facts_harvest` holds the figures fetched at build time, in a
 * column of their own so an import never touches what a person set: the
 * population with its year from Wikidata, `{value, year, qid}`
 * (`app:regions:import-population`). A person's figure shows over it.
 *
 * @api
 */
final class RegionFacts
{
    public const string AREA = 'area';
    public const string POPULATION = 'population';
    public const string RIDE = 'ride';

    /** The figures, in the order the page and the form show them. */
    public const array FIELDS = [self::AREA, self::POPULATION, self::RIDE];

    /** What riding a region is mostly like. */
    public const array RIDES = ['flat', 'hilly', 'mountainous'];

    /** Square kilometres: more than any country holds. */
    public const int AREA_MAX = 20_000_000;

    public const int POPULATION_MAX = 2_000_000_000;

    /** The oldest count a page may show. */
    public const int YEAR_MIN = 1900;

    /**
     * What the top of the region page shows: the stated area, else the
     * measured one; the population and its year, a person's else the
     * fetched one; the ride character. Null for a figure nobody set.
     *
     * @param array<string, mixed>|null $stored  decoded `region.facts`
     * @param array<string, mixed>|null $harvest decoded `region.facts_harvest`
     *
     * @return array{areaKm2: ?float, population: ?int, populationYear: ?int, ride: ?string}
     */
    public static function resolve(?array $stored, ?float $measuredKm2, ?array $harvest = null): array
    {
        $now = self::current($stored, $harvest);

        return [
            'areaKm2' => null !== $now['area'] ? (float) $now['area'] : $measuredKm2,
            'population' => $now['population'],
            'populationYear' => null !== $now['population'] ? $now['year'] : null,
            'ride' => $now['ride'],
        ];
    }

    /**
     * Each figure as it stands, null where none is set: a person's, else the
     * fetched one. `populationQid` names the Wikidata item when the
     * population shown is the fetched one. A malformed entry reads as none.
     *
     * @param array<string, mixed>|null $stored  decoded `region.facts`
     * @param array<string, mixed>|null $harvest decoded `region.facts_harvest`
     *
     * @return array{area: ?int, population: ?int, year: ?int, ride: ?string, populationQid: ?string}
     */
    public static function current(?array $stored, ?array $harvest = null): array
    {
        $area = self::entry($stored, self::AREA)['value'] ?? null;
        $ride = self::entry($stored, self::RIDE)['value'] ?? null;
        $count = self::count(self::entry($stored, self::POPULATION));
        $fetched = null === $count ? self::count(self::entry($harvest, self::POPULATION)) : null;
        $count ??= $fetched;
        $qid = $fetched['qid'] ?? null;

        return [
            'area' => \is_int($area) && $area > 0 ? $area : null,
            'population' => null !== $count ? $count['value'] : null,
            'year' => null !== $count ? $count['year'] : null,
            'ride' => \is_string($ride) && \in_array($ride, self::RIDES, true) ? $ride : null,
            'populationQid' => \is_string($qid) && 1 === preg_match('~^Q\d{1,12}$~', $qid) ? $qid : null,
        ];
    }

    /**
     * A population entry with a whole value and a year, else null.
     *
     * @param array<string, mixed>|null $entry
     *
     * @return array<string, mixed>|null
     */
    private static function count(?array $entry): ?array
    {
        return \is_int($entry['value'] ?? null) && \is_int($entry['year'] ?? null) ? $entry : null;
    }

    /**
     * One figure's stored entry, or null.
     *
     * @param array<string, mixed>|null $stored
     *
     * @return array<string, mixed>|null
     */
    public static function entry(?array $stored, string $field): ?array
    {
        $entry = $stored[$field] ?? null;

        return \is_array($entry) ? $entry : null;
    }

    /**
     * `$stored` with one figure set by an approved proposal, the others
     * untouched. `$value` is the area or the ride character, or for the
     * population `{value, year}`.
     *
     * @param array<string, mixed>|null $stored
     *
     * @return array<string, mixed>
     */
    public static function withEntry(?array $stored, string $field, mixed $value, ?int $submissionId, \DateTimeImmutable $at): array
    {
        $stored ??= [];
        $entry = self::POPULATION === $field && \is_array($value)
            ? ['value' => $value['value'] ?? null, 'year' => $value['year'] ?? null]
            : ['value' => $value];
        $stored[$field] = $entry + ['submissionId' => $submissionId, 'at' => $at->format(\DateTimeInterface::ATOM)];

        return $stored;
    }

    /**
     * Whether a proposal's value for a figure may be stored: an area in
     * range, a population in range with a year from YEAR_MIN, one of RIDES.
     */
    public static function isValid(string $field, mixed $value): bool
    {
        return match ($field) {
            self::AREA => \is_int($value) && $value >= 1 && $value <= self::AREA_MAX,
            self::POPULATION => \is_array($value)
                && \is_int($value['value'] ?? null) && $value['value'] >= 0 && $value['value'] <= self::POPULATION_MAX
                && \is_int($value['year'] ?? null) && $value['year'] >= self::YEAR_MIN,
            self::RIDE => \is_string($value) && \in_array($value, self::RIDES, true),
            default => false,
        };
    }

    /**
     * A figure's value as a proposal's `changes` carries it: the area and
     * the ride character as they are, the population as `{value, year}`.
     * Null when none is set.
     *
     * @param array{area: ?int, population: ?int, year: ?int, ride: ?string, populationQid: ?string} $current
     */
    public static function valueOf(array $current, string $field): mixed
    {
        return match ($field) {
            self::AREA => $current['area'],
            self::POPULATION => null !== $current['population'] ? ['value' => $current['population'], 'year' => $current['year']] : null,
            self::RIDE => $current['ride'],
            default => null,
        };
    }
}
