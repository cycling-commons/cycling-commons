<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Provider;

use App\Catalog\ItemSource;
use Doctrine\DBAL\Connection;

/**
 * Which of two records of one place is ours to keep, on one scale.
 *
 * The keeper order (data-provider-hierarchy.md §4) is
 * `manual` > `user` > `scout` > the registry's `rank` > `auto`:
 *
 *  - A rider's row sits above every registry number. The scale puts the
 *    three rider sources past {@see ProviderRegistry::RANK_MAX}, so no rank a
 *    curator can type reaches them.
 *  - `osm` and `wikidata` take their own registry rows' rank (100 and 200,
 *    which the desk refuses to move); an `authority` row takes its
 *    provider's rank through `item.provider_id`. So two providers holding one
 *    place are ordered by the numbers on the Providers desk, and a provider
 *    ranked below OpenStreetMap loses to it.
 *  - An `authority` row with no provider (the link is nullable) keeps the rung
 *    the fixed ladder gave every authority: just above Wikidata.
 *  - `auto` sits below every rank, including 0.
 *
 * Ranks are read fresh for each sweep ({@see self::resolver()}), so a rank a
 * curator saved a moment ago decides the next dedupe run and the next Data
 * desk scan.
 *
 * @see docs/specs/data-provider-hierarchy.md §4, §9.1
 *
 * @api
 */
final readonly class ProviderRank
{
    /** The rank a registry row for OpenStreetMap is seeded with, used when that row is missing. */
    public const int OSM_RANK = 100;

    /** The rank a registry row for Wikidata is seeded with, used when that row is missing. */
    public const int WIKIDATA_RANK = 200;

    public function __construct(private Connection $db)
    {
    }

    /**
     * One sweep's rank function, reading the registry once.
     *
     * @return \Closure(array<string, mixed>): int reads `source` and `provider_id` from a row
     */
    public function resolver(): \Closure
    {
        $byId = [];
        $byKey = [];
        /** @var list<array{id: int|string, provider_key: string, rank: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative('SELECT id, provider_key, rank FROM data_provider');
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = (int) $row['rank'];
            $byKey[$row['provider_key']] = (int) $row['rank'];
        }

        return static function (array $row) use ($byId, $byKey): int {
            $provider = $row['provider_id'] ?? null;

            return self::rank(
                ItemSource::tryFrom(\is_string($row['source'] ?? null) ? $row['source'] : ''),
                is_numeric($provider) ? $byId[(int) $provider] ?? null : null,
                $byKey,
            );
        };
    }

    /**
     * @param int|null           $providerRank the registry rank of the row's own provider, when it has one
     * @param array<string, int> $byKey        registry ranks by provider key
     */
    private static function rank(?ItemSource $source, ?int $providerRank, array $byKey): int
    {
        $wikidata = $byKey[ItemSource::Wikidata->value] ?? self::WIKIDATA_RANK;

        return match ($source) {
            ItemSource::Manual, ItemSource::User, ItemSource::Scout => ProviderRegistry::RANK_MAX + 1 + $source->dedupeRank(),
            ItemSource::Osm => $byKey[ItemSource::Osm->value] ?? self::OSM_RANK,
            ItemSource::Wikidata => $wikidata,
            ItemSource::Authority => $providerRank ?? $wikidata + 1,
            ItemSource::Auto, null => -1,
        };
    }
}
