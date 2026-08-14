<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\ItemUpsert;
use App\Catalog\Import\ProvinceMap;
use App\Catalog\ItemSource;
use App\Catalog\ItemType;
use App\Catalog\ServiceKind;
use App\Catalog\SurfaceProfiler;
use App\Service\BaseLocationService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports catalog export artifacts (tools/wallonia/out) into the database:
 * regions first, then item layers; items upsert by (source, source_ref, letter)
 * - one entity may carry two classifications - routes by (source, source_ref);
 * region membership recomputed every run. Updates never touch lifecycle state.
 *
 * @see docs/specs/catalog-data-model.md §8
 *
 * @api Console entry point, run via `make` or the CLI.
 */
#[AsCommand(name: 'app:catalog:import', description: 'Import catalog export artifacts (regions, items) into the database')]
final class ImportCatalogCommand extends Command
{
    /** Geometry kind each letter must carry (registry LocationMode made concrete). */
    private const array GEOMETRY_KIND = [
        'A' => 'LineString',
        'B' => 'Point', 'C' => 'Point', 'D' => 'Point', 'E' => 'Point', 'F' => 'Point',
        'G' => 'Point', 'H' => 'Point', 'I' => 'Point', 'J' => 'Point',
    ];

    /** Property keys consumed into columns - never stored as attributes. */
    private const array CONSUMED_KEYS = ['n', 'name', 'prov', 'source', 'ref'];

    /**
     * Same-country, same-level regions overlapping by more than this fraction
     * of the smaller one's area are rejected as a bad import (mismatched
     * operating levels or duplicated geometry); at or below it the overlap is
     * a digitization sliver between adjacent OSM/Overture admin boundaries and
     * is tolerated (see assertRegionsTessellate).
     */
    private const float REGION_OVERLAP_TOLERANCE = 0.001;

    public function __construct(
        private readonly Connection $db,
        private readonly AttributeVocabulary $vocabulary,
        private readonly SurfaceProfiler $surfaces,
        private readonly BaseLocationService $baseLocations,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('dir', InputArgument::REQUIRED, 'Directory holding the export artifacts');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = rtrim((string) $input->getArgument('dir'), '/');
        if (!is_dir($dir)) {
            $io->error(sprintf('Not a directory: %s', $dir));

            return Command::FAILURE;
        }

        try {
            $this->db->beginTransaction();
            $regions = $this->importRegions($dir, $io);
            $this->recomputeAdjacency();
            $this->recomputeOutlines();
            $items = $this->importItemLayers($dir, $io);
            $routes = $this->importRoutes($dir, $io);
            $heat = $this->importHeat($dir, $io);
            $assigned = $this->recomputeMembership();
            // Rider base areas depend on region geometry, which this run may have
            // just changed - re-derive every rider's set inside the same
            // transaction so it never drifts from what was just imported (no
            // queue in this app, so re-derivation is transactional-inline by
            // design, map-and-search.md §4.5).
            $rederived = $this->baseLocations->rederiveAll();
            $io->text(sprintf('Re-derived base areas for %d rider(s).', $rederived));
            // Derived surfaces depend on the freshly-upserted A-layer + routes,
            // so recompute after membership, inside the same transaction.
            $surfaced = $this->surfaces->recomputeAll();
            $this->db->commit();
        } catch (\InvalidArgumentException|\JsonException|DBALException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Catalog import: %d region(s), %d item(s) upserted, %d route(s), %d heat point(s), %d region-assigned, %d route surface profile(s).', $regions, $items, $routes, $heat, $assigned, $surfaced));

        return Command::SUCCESS;
    }

    private function importRegions(string $dir, SymfonyStyle $io): int
    {
        $count = 0;
        foreach (glob($dir.'/region-*.geojson') ?: [] as $file) {
            /** @var array{properties: array{slug: string, name: string, area_km2?: float|int, country_code?: mixed, iso_code?: mixed, admin_level?: mixed, source?: mixed}, geometry: array<string, mixed>} $feature */
            $feature = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            $props = $feature['properties'];
            [$countryCode, $isoCode, $adminLevel, $source] = $this->regionProvenance($props, $file);
            // Stamp country_code / iso_code / admin_level / source from the
            // artifact (map-and-search.md §4.5 Phase 1). country_code is
            // required and validated at the door: a region row with no country is
            // a SILENT moderation-jurisdiction hole — country-scoped curators
            // match on region.country_code (ModerationScope), so an unstamped
            // region is invisible to them (map-and-search.md §4.5 risk 1).
            // The four columns join the change-detection tuple so a re-import
            // that only changes provenance still bumps updated_at.
            $this->db->executeStatement(
                'INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
                 VALUES (:slug, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :area, :cc, :iso, :admin, :source, NOW(), NOW())
                 ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name, geom = EXCLUDED.geom,
                   area_km2 = EXCLUDED.area_km2, country_code = EXCLUDED.country_code,
                   iso_code = EXCLUDED.iso_code, admin_level = EXCLUDED.admin_level, source = EXCLUDED.source,
                   updated_at = CASE WHEN (region.name, ST_AsEWKB(region.geom), region.area_km2, region.country_code, region.iso_code, region.admin_level, region.source)
                                     IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.area_km2, EXCLUDED.country_code, EXCLUDED.iso_code, EXCLUDED.admin_level, EXCLUDED.source)
                                THEN NOW() ELSE region.updated_at END',
                [
                    'slug' => $feature['properties']['slug'],
                    'name' => $feature['properties']['name'],
                    'geom' => json_encode($feature['geometry'], \JSON_THROW_ON_ERROR),
                    'area' => $feature['properties']['area_km2'] ?? null,
                    'cc' => $countryCode,
                    'iso' => $isoCode,
                    'admin' => $adminLevel,
                    'source' => $source,
                ],
            );
            ++$count;
            $io->writeln(sprintf('  region %s', $feature['properties']['slug']));
        }

        // Operating-level regions must tessellate within a country, never
        // overlap: an ST_Overlaps pair means a bad import (mismatched levels or
        // duplicated geometry). Reject it here so the transaction rolls back —
        // the import check PREVENTS the ambiguity, while the smallest-area-wins
        // ordering in recomputeMembership SURVIVES one that slips through
        // (map-and-search.md §4.5).
        $this->assertRegionsTessellate();

        return $count;
    }

    /**
     * Extracts and validates a region artifact's provenance columns.
     * `country_code` is required (2-letter ISO 3166-1, uppercased); the rest
     * are optional and default to NULL.
     *
     * @param array<string, mixed> $props
     *
     * @return array{0: string, 1: string|null, 2: int|null, 3: string|null} [countryCode, isoCode, adminLevel, source]
     */
    private function regionProvenance(array $props, string $file): array
    {
        $rawCc = \is_string($props['country_code'] ?? null) ? strtoupper(trim($props['country_code'])) : '';
        if (1 !== preg_match('/^[A-Z]{2}$/', $rawCc)) {
            throw new \InvalidArgumentException(sprintf('%s: region artifact missing required 2-letter country_code (got %s) — an unstamped region is a silent moderation-jurisdiction hole (map-and-search.md §4.5).', basename($file), '' === $rawCc ? '<missing>' : sprintf('"%s"', $rawCc)));
        }

        $iso = \is_string($props['iso_code'] ?? null) && '' !== trim($props['iso_code'])
            ? strtoupper(trim($props['iso_code'])) : null;
        $admin = \is_numeric($props['admin_level'] ?? null) ? (int) $props['admin_level'] : null;
        $source = \is_string($props['source'] ?? null) && '' !== trim($props['source'])
            ? trim($props['source']) : null;

        return [$rawCc, $iso, $admin, $source];
    }

    /**
     * @throws \InvalidArgumentException when two same-country, same-level regions overlap
     */
    private function assertRegionsTessellate(): void
    {
        // The tessellation invariant is per (country, admin_level): same-level
        // regions must tile a country without overlapping, but cross-level
        // pairs are EXPECTED containment (e.g. a country's admin_level=2
        // outline containing its admin_level=4 subdivisions under the 2+4
        // playbook) — that ambiguity is resolved deterministically by
        // recomputeMembership's smallest-area-wins, not rejected here.
        // IS NOT DISTINCT FROM
        // keeps the guard live for legacy NULL-level rows, so two NULL-level
        // regions in one country still may not overlap.
        //
        // ST_Overlaps already excludes a shared border (a line, not an area) and
        // nested containment (that is ST_Contains — handled deterministically by
        // recomputeMembership's smallest-area-wins). The area-ratio gate on top
        // tolerates the sub-permille slivers real adjacent OSM/Overture admin
        // boundaries carry, so only a MEANINGFUL overlap trips the guard.
        /** @var array{a: string, b: string}|false $overlap */
        $overlap = $this->db->fetchAssociative(
            "SELECT a.slug AS a, b.slug AS b
               FROM region a JOIN region b ON a.id < b.id
              WHERE a.country_code = b.country_code AND a.country_code <> ''
                AND a.admin_level IS NOT DISTINCT FROM b.admin_level
                AND a.geom IS NOT NULL AND b.geom IS NOT NULL
                AND ST_Overlaps(a.geom, b.geom)
                AND ST_Area(ST_Intersection(a.geom, b.geom))
                    > :tol * LEAST(ST_Area(a.geom), ST_Area(b.geom))
              LIMIT 1",
            ['tol' => self::REGION_OVERLAP_TOLERANCE],
        );
        if (false !== $overlap) {
            throw new \InvalidArgumentException(sprintf('Region overlap: "%s" and "%s" share more than a boundary sliver at the same admin level within one country — same-level regions must tessellate, not overlap (map-and-search.md §4.5; cross-level containment is expected, map-and-search.md §4.5).', $overlap['a'], $overlap['b']));
        }
    }

    private function importItemLayers(string $dir, SymfonyStyle $io): int
    {
        $subdivisions = $this->subdivisionIdsByCode();
        $total = 0;
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            /** @var array{layer?: string, letter?: string, features?: list<array{properties: array<string, mixed>, geometry: array{type: string, coordinates: array<mixed>}}>} $payload */
            $payload = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            if (!isset($payload['letter'], $payload['features'])) {
                continue; // routes.json / heat.json are handled by their own importers
            }
            $letter = (string) $payload['letter'];
            $type = ItemType::fromParam($letter);
            $expectedGeometry = self::GEOMETRY_KIND[$letter]
                ?? throw new \InvalidArgumentException(sprintf('No geometry kind for letter %s', $letter));

            foreach ($payload['features'] as $feature) {
                $props = $feature['properties'];
                $geometry = $feature['geometry'];
                if ($geometry['type'] !== $expectedGeometry) {
                    throw new \InvalidArgumentException(sprintf('Letter %s expects %s geometry, got %s (ref %s)', $letter, $expectedGeometry, $geometry['type'], (string) ($props['ref'] ?? '?')));
                }

                $attributes = array_diff_key($props, array_flip(self::CONSUMED_KEYS));
                // Defense-in-depth for the D-kind split: derive serviceKind from the
                // legacy `t` label whenever the incoming feature lacks it, so stale
                // export artifacts (produced before the harvester started stamping
                // serviceKind directly) still import with the correct kind.
                if (ItemType::BikeServices === $type && !isset($attributes['serviceKind'])) {
                    $kind = ServiceKind::fromLegacyLabel(\is_string($attributes['t'] ?? null) ? $attributes['t'] : null);
                    if (null !== $kind) {
                        $attributes['serviceKind'] = $kind->value;
                    }
                }
                $this->vocabulary->assertValid($type, $attributes);

                [$source, $ref] = $this->resolveSourceRef($props, $file);

                $prov = (string) ($props['prov'] ?? '');
                // Shared upsert; preserves curator-approved edits on re-harvest
                // (see ItemUpsert).
                $this->db->executeStatement(
                    ItemUpsert::SQL,
                    [
                        'letter' => $letter,
                        // Two live producers, not old-data tolerance: the bulk OSM
                        // pools emit the short key 'n' (services/stays/water/…),
                        // while the climbs and surface exporters emit 'name'
                        // (10/10 resp. 351/351 features in the real artifacts).
                        'name' => (string) ($props['n'] ?? $props['name'] ?? ''),
                        'geom' => json_encode($geometry, \JSON_THROW_ON_ERROR),
                        'cc' => 'BE',
                        'sub' => $subdivisions[ProvinceMap::CODES[$prov] ?? ''] ?? null,
                        'state' => 'unverified',
                        'source' => $source,
                        'ref' => $ref,
                        'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                    ],
                );
                ++$total;
            }
            $io->writeln(sprintf('  %s: %d feature(s)', basename($file), \count($payload['features'])));
        }

        return $total;
    }

    private function importRoutes(string $dir, SymfonyStyle $io): int
    {
        $file = $dir.'/routes.json';
        if (!is_file($file)) {
            return 0;
        }
        /** @var array{routes: list<array{name: string, source?: mixed, ref?: mixed, distance_m: int, ascent_m: int, geometry: array<string, mixed>, attributes: array<string, mixed>}>} $payload */
        $payload = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($payload['routes'] as $route) {
            [$source, $ref] = $this->resolveSourceRef($route, $file);
            // The harvest artifact seeds season as a lowercase scalar
            // ('summer', tools/wallonia/routes.py); the canonical stored shape
            // is the capitalized list the proposal form writes (['Summer'],
            // route-domain.md §12). Normalize at intake so re-imports never
            // reintroduce the scalar and readers see exactly one shape.
            $route['attributes'] = $this->normalizeSeason($route['attributes']);
            $this->db->executeStatement(
                'INSERT INTO recommended_route (name, geom, distance_m, ascent_m, state, source, source_ref, attributes, created_at, updated_at, imported_at)
                 VALUES (:name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :dist, :ascent, :state, :source, :ref, :attrs, NOW(), NOW(), NOW())
                 ON CONFLICT (source, source_ref) DO UPDATE SET
                   name = EXCLUDED.name, geom = EXCLUDED.geom, distance_m = EXCLUDED.distance_m,
                   ascent_m = EXCLUDED.ascent_m, attributes = EXCLUDED.attributes,
                   updated_at = CASE WHEN (recommended_route.name, ST_AsEWKB(recommended_route.geom), recommended_route.distance_m, recommended_route.ascent_m, recommended_route.attributes)
                                     IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.distance_m, EXCLUDED.ascent_m, EXCLUDED.attributes)
                                THEN NOW() ELSE recommended_route.updated_at END,
                   imported_at = NOW()',
                [
                    'name' => $route['name'],
                    'geom' => json_encode($route['geometry'], \JSON_THROW_ON_ERROR),
                    'dist' => $route['distance_m'],
                    'ascent' => $route['ascent_m'],
                    'state' => 'unverified',
                    'source' => $source,
                    'ref' => $ref,
                    'attrs' => json_encode($route['attributes'], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                ],
            );
        }
        $io->writeln(sprintf('  routes.json: %d route(s)', \count($payload['routes'])));

        return \count($payload['routes']);
    }

    /**
     * Canonicalize attributes.season to the stored list shape: scalar or list,
     * any case, → capitalized list over Spring/Summer/Autumn/Winter. Unknown
     * entries are dropped; an empty result removes the key entirely.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function normalizeSeason(array $attributes): array
    {
        if (!\array_key_exists('season', $attributes)) {
            return $attributes;
        }
        $canonical = ['spring' => 'Spring', 'summer' => 'Summer', 'autumn' => 'Autumn', 'winter' => 'Winter'];
        $raw = \is_array($attributes['season']) ? $attributes['season'] : [$attributes['season']];
        $seasons = [];
        foreach ($raw as $value) {
            $season = \is_string($value) ? ($canonical[mb_strtolower($value)] ?? null) : null;
            if (null !== $season && !\in_array($season, $seasons, true)) {
                $seasons[] = $season;
            }
        }
        if ([] === $seasons) {
            unset($attributes['season']);

            return $attributes;
        }
        $attributes['season'] = $seasons;

        return $attributes;
    }

    private function importHeat(string $dir, SymfonyStyle $io): int
    {
        $file = $dir.'/heat.json';
        if (!is_file($file)) {
            return 0;
        }
        /** @var array{points: list<array{0: float, 1: float, 2?: string}>} $payload */
        $payload = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        $this->db->executeStatement("DELETE FROM heat_point WHERE source = 'auto'");
        foreach (array_chunk($payload['points'], 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $i => $point) {
                // fixture order is [lat, lng, season] - ST_Point takes (x=lng, y=lat)
                $values[] = sprintf('(ST_SetSRID(ST_Point(:lng%1$d, :lat%1$d), 4326), 1.0, \'auto\', :season%1$d, NOW())', $i);
                $params['lng'.$i] = $point[1];
                $params['lat'.$i] = $point[0];
                $params['season'.$i] = $point[2] ?? null;
            }
            $this->db->executeStatement(
                'INSERT INTO heat_point (geom, weight, source, season, computed_at) VALUES '.implode(', ', $values),
                $params,
            );
        }
        $io->writeln(sprintf('  heat.json: %d point(s)', \count($payload['points'])));

        return \count($payload['points']);
    }

    /**
     * Validates and extracts the (source, ref) pair a file-supplied record must
     * carry: both keys present and non-empty, and `source` a real ItemSource -
     * a drifted/hand-made export with a bad or missing source would otherwise
     * insert fine into the varchar column and only blow up later, poisoning
     * every ORM read that hydrates the enum.
     *
     * @param array<string, mixed> $record
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSourceRef(array $record, string $file): array
    {
        $source = $record['source'] ?? null;
        $ref = $record['ref'] ?? null;
        if (!\is_string($source) || '' === $source || !\is_string($ref) || '' === $ref) {
            throw new \InvalidArgumentException(sprintf('%s: record missing required source/ref (source=%s, ref=%s)', basename($file), \is_string($source) && '' !== $source ? $source : '<missing>', \is_string($ref) && '' !== $ref ? $ref : '<missing>'));
        }
        if (null === ItemSource::tryFrom($source)) {
            throw new \InvalidArgumentException(sprintf('%s: unknown source "%s" for ref %s', basename($file), $source, $ref));
        }

        return [$source, $ref];
    }

    /** @return array<string, int> subdivision code => id (empty when world data is not seeded) */
    private function subdivisionIdsByCode(): array
    {
        $codes = array_values(ProvinceMap::CODES);
        $placeholders = implode(', ', array_fill(0, \count($codes), '?'));

        /** @var list<array{code: string, id: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            sprintf('SELECT code, id FROM world_subdivision WHERE code IN (%s)', $placeholders),
            $codes,
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['code']] = (int) $row['id'];
        }

        return $map;
    }

    private function recomputeMembership(): int
    {
        // Smallest-area-wins on overlap: DISTINCT ON keeps exactly one region
        // per row, ordered by area then id, so membership is deterministic
        // regardless of region row order once regions multiply past the single
        // Wallonia seed (map-and-search.md §4.5). Rows in no region stay
        // NULL (the reset above is never overwritten for them).
        $this->db->executeStatement('UPDATE item SET region_id = NULL');
        $assigned = (int) $this->db->executeStatement(
            'UPDATE item SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (i.id) i.id AS item_id, r.id AS region_id
                FROM item i JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(i.geom))
                ORDER BY i.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE item.id = m.item_id',
        );

        $this->db->executeStatement('UPDATE recommended_route SET region_id = NULL');
        $assigned += (int) $this->db->executeStatement(
            'UPDATE recommended_route SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (rr.id) rr.id AS route_id, r.id AS region_id
                FROM recommended_route rr JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(rr.geom))
                ORDER BY rr.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE recommended_route.id = m.route_id',
        );

        // Heat points too (07-20 review finding 5): the ride-heat layer filters
        // client-side on the scope like every served layer, so its points carry
        // rid — an unstamped heat point would render in every scope or none.
        // Point geometry: ST_Contains against the point directly.
        $this->db->executeStatement('UPDATE heat_point SET region_id = NULL');
        $assigned += (int) $this->db->executeStatement(
            'UPDATE heat_point SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (h.id) h.id AS heat_id, r.id AS region_id
                FROM heat_point h JOIN region r ON ST_Contains(r.geom, h.geom)
                ORDER BY h.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE heat_point.id = m.heat_id',
        );

        return $assigned;
    }

    /**
     * Recompute each region's border-neighbour id list (region.adj) across ALL
     * onboarded countries — the cross-border adjacency the scope chips gate on.
     * Derived,
     * recomputed every import, never authored. ST_Intersects rides the existing
     * idx_region_geom GiST index (bbox prefilter → exact only on truly-touching
     * pairs), so cost scales with border count, not region count squared. Empty
     * array (never NULL) for a region touching nothing, so the client can treat a
     * post-import region as "known, borders none" rather than "not yet computed".
     */
    private function recomputeAdjacency(): void
    {
        $this->db->executeStatement(
            'UPDATE region a SET adj = COALESCE((
                SELECT array_agg(b.id ORDER BY b.id)
                FROM region b
                WHERE b.id <> a.id AND ST_Intersects(a.geom, b.geom)
             ), ARRAY[]::int[])
             WHERE a.geom IS NOT NULL'
        );
    }

    /**
     * Recompute each region's simplified ranking outline (region.outline) — the
     * geometry the scope chips rank against.
     * Derived, recomputed
     * every import beside adj, never authored.
     *
     * This is a RANKING metric, not a geometry source: real boundaries still come
     * from RegionBoundaryProvider. It ships inlined in CC_REGIONS, so the shape is
     * chosen for bytes over fidelity — parts smaller than max(1% of the region,
     * 5 km²) are dropped (the largest part always survives, whatever its size),
     * exterior rings only, ST_SimplifyPreserveTopology at 0.05° (~5.5 km, well
     * under the tens of km the ranking actually distinguishes), flat
     * [lng,lat,lng,lat,…] per ring, 3 decimals (~110 m, so rounding never
     * dominates simplification). 32 regions → 40 rings / 1,142 points / ~18 kB.
     *
     * Empty array (never NULL) when a region has no usable part, which the client
     * reads as "no outline" and falls back to the bbox centre.
     */
    private function recomputeOutlines(): void
    {
        $this->db->executeStatement(self::OUTLINE_SQL);
    }

    /**
     * Kept as a constant because Version20260727120000 backfills existing rows
     * with the identical statement, and the two must not drift.
     */
    /**
     * EVERY GEOGRAPHY AREA IS COMPUTED ONCE PER PART. That is the whole reason
     * this is shaped the way it is, and it is not a micro-optimisation.
     *
     * The first version put `ST_Area(r.geom::geography) * 0.01` in the WHERE —
     * a correlated reference to the OUTER row, re-evaluated for every part
     * `ST_Dump` produced, each time casting the entire multipolygon to
     * geography and measuring it. Cost is therefore parts × cost(whole
     * geometry), which is quadratic in the thing that makes a country big.
     *
     * Measured on `united-states` alone (6,128 parts, 136,302 points):
     * **>600 s and still running**, against **653 ms** once the area is
     * computed once. It is not "slow" — a rollout adding Canada (an outline
     * with more parts still) never finishes, and the 2026-08-14 import sat on
     * this statement for 80 minutes before it was diagnosed.
     *
     * The replacement is `sum(a) OVER ()`: a multipolygon's area IS the sum of
     * its parts' areas, so the total comes free from values already computed
     * per part. `max(a) OVER ()` reuses the same column rather than measuring
     * a second time. The window sits in a subquery and the filter outside it,
     * so both windows see every part — including the ones the filter drops,
     * which is what keeps `total` equal to the whole region's area.
     *
     * `a` stays the area of the UNSIMPLIFIED part while `g` is the simplified
     * shape, exactly as before: the size test decides whether a part is worth
     * keeping, and simplification must not be able to shrink a part out of the
     * answer.
     *
     * Kept as a constant because Version20260727120000 backfills existing rows
     * with the identical statement, and the two must not drift.
     */
    public const OUTLINE_SQL = <<<'SQL'
        UPDATE region r SET outline = COALESCE((
            SELECT json_agg(ring)
            FROM (
                SELECT (
                    SELECT json_agg(round(v::numeric, 3) ORDER BY o)
                    FROM (
                        SELECT unnest(ARRAY[ST_X(p.geom), ST_Y(p.geom)]) AS v,
                               (p.path[1] * 2) + generate_series(0, 1) AS o
                        FROM ST_DumpPoints(ST_ExteriorRing(z.g)) p
                    ) pt
                ) AS ring
                FROM (
                    SELECT parts.g,
                           parts.a,
                           max(parts.a) OVER () AS mx,
                           sum(parts.a) OVER () AS total
                    FROM (
                        SELECT ST_SimplifyPreserveTopology(d.geom, 0.05) AS g,
                               ST_Area(d.geom::geography) AS a
                        FROM ST_Dump(r.geom) d
                    ) parts
                ) z
                WHERE z.g IS NOT NULL
                  AND GeometryType(z.g) = 'POLYGON'
                  AND z.a >= LEAST(GREATEST(z.total * 0.01, 5e6), z.mx)
            ) rings
        ), '[]'::json)::jsonb
        WHERE r.geom IS NOT NULL
        SQL;
}
