<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\OperationalRegions;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The regions a ballot list can belong to: operational regions with an
 * outline and a country, by name. One query for the ballot page and the
 * results page, so both offer the same regions.
 *
 * `mid` is the middle latitude of the region's bounding box, the input of
 * {@see Hemisphere::ofLatitude()}.
 *
 * @phpstan-type RegionRow array{id: int, slug: string, name: string, countryCode: string, mid: float}
 *
 * @see docs/specs/route-domain.md §8d
 *
 * @api
 */
final class BallotRegions
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<RegionRow> */
    public function all(): array
    {
        return $this->rows('TRUE', []);
    }

    /** @return list<RegionRow> */
    public function inCountry(string $countryCode): array
    {
        return $this->rows('g.country_code = :cc', ['cc' => $countryCode]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<RegionRow>
     */
    public function withIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->rows('g.id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
    }

    /**
     * Every region, grouped under its country's name in the reader's
     * language, countries in that language's alphabetical order.
     *
     * @return array<string, list<RegionRow>>
     */
    public function byCountry(string $locale): array
    {
        $out = [];
        foreach ($this->all() as $r) {
            $country = \Locale::getDisplayRegion('-'.$r['countryCode'], $locale);
            $out['' !== $country ? $country : $r['countryCode']][] = $r;
        }
        $collator = new \Collator($locale);
        uksort($out, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $out;
    }

    /**
     * @param array<string, mixed>              $params
     * @param array<string, ArrayParameterType> $types
     *
     * @return list<RegionRow>
     */
    private function rows(string $condition, array $params, array $types = []): array
    {
        /** @var list<array{id: int|string, slug: string, name: string, country_code: string, mid: float|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT g.id, g.slug, g.name, g.country_code, (g.bbox_s + g.bbox_n) / 2 AS mid
               FROM region g
              WHERE g.geom IS NOT NULL AND g.country_code <> '' AND ".OperationalRegions::predicate('g').' AND '.$condition.'
              ORDER BY g.name, g.id',
            $params,
            $types,
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'countryCode' => $r['country_code'],
            'mid' => (float) $r['mid'],
        ], $rows);
    }
}
