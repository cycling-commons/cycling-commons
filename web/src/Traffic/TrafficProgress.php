<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * How far measured traffic has come, per country and region
 * (docs/specs/traffic-measurements.md §4.6): of the roads with any data, how
 * many are still building and how many are usable (past the disclosure rules).
 *
 * Counts of roads only. A road's own numbers show nowhere until it is usable,
 * so this never says which road one rider rode. A road's region comes with its
 * lines: the road-piece tiles name it, the browser sends it, the cell keeps it.
 * The countries are the onboarded ones (the road-piece manifest) and any
 * country that already has data.
 *
 * @api
 */
final class TrafficProgress
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<int, int|null> $regionOfWay every road with data in the period, with its region
     * @param list<int>            $usable      the roads with at least one usable group
     * @param list<string>         $onboarded   country codes with road-piece tiles
     *
     * @return array{countries: list<array{country: string, name: string, building: int, usable: int, regions: list<array{name: string, building: int, usable: int}>}>, unplaced: int}
     */
    public function count(array $regionOfWay, array $usable, array $onboarded): array
    {
        $regionIds = array_values(array_unique(array_filter($regionOfWay, static fn (?int $r): bool => null !== $r)));
        $codes = array_map(strtoupper(...), $onboarded);
        if ([] !== $regionIds) {
            $codes = array_merge($codes, array_map(strval(...), $this->db->fetchFirstColumn(
                'SELECT DISTINCT upper(country_code) FROM region WHERE id IN (:ids)',
                ['ids' => $regionIds],
                ['ids' => ArrayParameterType::INTEGER],
            )));
        }
        $codes = array_values(array_unique(array_filter($codes, static fn (string $c): bool => '' !== $c)));
        sort($codes);
        if ([] === $codes) {
            return ['countries' => [], 'unplaced' => \count($regionOfWay)];
        }

        $countries = [];
        foreach ($codes as $cc) {
            $countries[$cc] = ['country' => $cc, 'name' => $cc, 'building' => 0, 'usable' => 0, 'regions' => []];
        }
        $named = $this->db->fetchAllKeyValue(
            'SELECT iso2, name FROM world_country WHERE iso2 IN (:cc)',
            ['cc' => $codes],
            ['cc' => ArrayParameterType::STRING],
        );
        foreach ($named as $iso => $name) {
            $countries[(string) $iso]['name'] = (string) $name;
        }
        $countryOf = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, upper(country_code) AS cc FROM region WHERE upper(country_code) IN (:cc) AND geom IS NOT NULL ORDER BY name',
            ['cc' => $codes],
            ['cc' => ArrayParameterType::STRING],
        );
        foreach ($rows as $r) {
            $countries[(string) $r['cc']]['regions'][(int) $r['id']] = ['name' => (string) $r['name'], 'building' => 0, 'usable' => 0];
            $countryOf[(int) $r['id']] = (string) $r['cc'];
        }

        $isUsable = array_fill_keys($usable, true);
        $unplaced = 0;
        foreach ($regionOfWay as $way => $region) {
            $cc = null === $region ? null : ($countryOf[$region] ?? null);
            if (null === $cc) {
                ++$unplaced;
                continue;
            }
            $kind = isset($isUsable[$way]) ? 'usable' : 'building';
            ++$countries[$cc][$kind];
            ++$countries[$cc]['regions'][$region][$kind];
        }

        foreach ($countries as $cc => $c) {
            $countries[$cc]['regions'] = array_values($c['regions']);
        }

        return ['countries' => array_values($countries), 'unplaced' => $unplaced];
    }
}
