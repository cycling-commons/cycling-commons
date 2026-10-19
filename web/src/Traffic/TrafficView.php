<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use App\Coverage\RoadPiecesManifest;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What curators may see of the traffic totals, after the disclosure rules
 * (docs/specs/traffic-measurements.md §4.4, §4.6).
 *
 * It is recomputed on a fixed cadence, never per upload: a view that changed
 * with every write would let a curator read one rider's upload off as the
 * difference between two views. The cache key carries the scheme, so a scheme
 * change shows at once and never mixes with the old grouping. The cached copy
 * is sealed like the rows it came from: a cache dump must not hold what a
 * database dump does not. Felt safety (§10) is built in the same pass, under
 * the same scheme and period, and sealed in the same entry.
 *
 * @api
 */
final class TrafficView
{
    private const string CACHE_KEY = 'traffic.view.v8.';
    /** How often the view is recomputed, in seconds. */
    public const int CADENCE = 21600;
    /** How far back the shown numbers reach, in quarters of a year. */
    public const int PERIOD_QUARTERS = 12;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly TrafficStore $store,
        private readonly TrafficCipher $cipher,
        private readonly TrafficDisclosure $disclosure,
        private readonly SettingsProviderInterface $settings,
        private readonly ClockInterface $clock,
        private readonly TrafficProgress $progress,
        private readonly RoadPiecesManifest $roadPieces,
        private readonly SafetyStore $safetyStore,
        private readonly SafetyDisclosure $safetyDisclosure,
    ) {
    }

    /** @return list<array{way: int, dir: string, label: string, group: string, traffic: string, nearby: string, carSpeedBand: int|null, days: string}> */
    public function shown(): array
    {
        /* @var list<array{way: int, dir: string, label: string, group: string, traffic: string, nearby: string, carSpeedBand: int|null, days: string}> */
        return $this->built()['shown'];
    }

    /**
     * Roads with data per onboarded country and region: still building, or
     * usable. Built with the shown entries, on the same cadence.
     *
     * @return array{countries: list<array{country: string, name: string, building: int, usable: int, regions: list<array{name: string, building: int, usable: int}>}>, unplaced: int}
     */
    public function progress(): array
    {
        /* @var array{countries: list<array{country: string, name: string, building: int, usable: int, regions: list<array{name: string, building: int, usable: int}>}>, unplaced: int} */
        return $this->built()['progress'];
    }

    /**
     * Felt safety per road and group that passed its rule: a band, never a count.
     *
     * @return list<array{way: int, group: string, felt: string}>
     */
    public function felt(): array
    {
        /* @var list<array{way: int, group: string, felt: string}> */
        return $this->built()['felt'];
    }

    /**
     * Roads with felt-safety marks per onboarded country and region: still
     * building, or usable. Counts of roads only, as for measured traffic.
     *
     * @return array{countries: list<array{country: string, name: string, building: int, usable: int, regions: list<array{name: string, building: int, usable: int}>}>, unplaced: int}
     */
    public function feltProgress(): array
    {
        /* @var array{countries: list<array{country: string, name: string, building: int, usable: int, regions: list<array{name: string, building: int, usable: int}>}>, unplaced: int} */
        return $this->built()['feltProgress'];
    }

    /** When the numbers on screen were built: the cadence's last rebuild. */
    public function builtAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.(int) $this->built()['builtAt']);
    }

    /** @return array{shown: list<array<string, mixed>>, progress: array<string, mixed>, felt: list<array<string, mixed>>, feltProgress: array<string, mixed>, builtAt: int} */
    private function built(): array
    {
        $sealed = $this->cache->get($this->cacheKey(), function (ItemInterface $item): string {
            $item->expiresAfter(self::CADENCE);
            $since = $this->sinceQuarter();
            $rows = [];
            $regionOfWay = [];
            foreach ($this->store->totals() as $row) {
                $rows[] = $row;
                if (strcmp($row['quarter'], $since) >= 0) {
                    $regionOfWay[$row['way']] ??= $row['region'];
                }
            }
            $shown = $this->disclosure->evaluate($rows, $this->scheme(), $since);
            $usable = array_values(array_unique(array_column($shown, 'way')));

            $marks = [];
            $regionOfMarkedWay = [];
            foreach ($this->safetyStore->totals() as $row) {
                $marks[] = $row;
                if (strcmp($row['quarter'], $since) >= 0) {
                    $regionOfMarkedWay[$row['way']] ??= $row['region'];
                }
            }
            $felt = $this->safetyDisclosure->evaluate($marks, $this->scheme(), $since);
            $onboarded = array_map(strval(...), array_keys($this->roadPieces->countryTiles()));

            return $this->cipher->seal([
                'shown' => $shown,
                'builtAt' => $this->clock->now()->getTimestamp(),
                'progress' => $this->progress->count($regionOfWay, $usable, $onboarded),
                'felt' => $felt,
                'feltProgress' => $this->progress->count($regionOfMarkedWay, array_values(array_unique(array_column($felt, 'way'))), $onboarded),
            ], 'traffic.view');
        });

        /* @var array{shown: list<array<string, mixed>>, progress: array<string, mixed>, felt: list<array<string, mixed>>, feltProgress: array<string, mixed>, builtAt: int} */
        return $this->cipher->open($sealed, 'traffic.view');
    }

    public function scheme(): string
    {
        return $this->settings->getString(SettingsRegistry::TRAFFIC_GROUPING);
    }

    /** Drops the current period's view; the next read recomputes it. */
    public function invalidate(): void
    {
        $this->cache->delete($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY.$this->scheme().'.'.intdiv($this->clock->now()->getTimestamp(), self::CADENCE);
    }

    /** The first quarter inside the period, as 'YYYY-Qn'. */
    private function sinceQuarter(): string
    {
        $now = $this->clock->now();
        $index = (int) $now->format('Y') * 4 + intdiv((int) $now->format('n') - 1, 3) - (self::PERIOD_QUARTERS - 1);

        return intdiv($index, 4).'-Q'.($index % 4 + 1);
    }
}
