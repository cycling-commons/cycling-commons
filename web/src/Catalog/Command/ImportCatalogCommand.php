<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\DuplicateGuard;
use App\Catalog\Import\ItemUpsert;
use App\Catalog\Import\ProvinceMap;
use App\Catalog\ItemSource;
use App\Catalog\ItemType;
use App\Catalog\OperationalRegions;
use App\Catalog\ServiceKind;
use App\Catalog\SurfaceProfiler;
use App\Media\Commons\CommonsFile;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
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
 * Import catalog export artifacts. Upsert by (source, source_ref, letter); updates never touch lifecycle state.
 *
 * A feature's `photo` / `photos` are written only when PhotoValidator shows
 * them on that feature; a refused photo is left off (the row is still
 * imported) and named in the report with the reason.
 *
 * @see docs/specs/catalog-data-model.md §8
 * @see docs/specs/photo-uploads.md §5h
 *
 * @api
 */
#[AsCommand(name: 'app:catalog:import', description: 'Import catalog export artifacts (regions, items) into the database')]
final class ImportCatalogCommand extends Command
{
    /** Geometry kind each letter must carry (registry LocationMode made concrete). */
    private const array GEOMETRY_KIND = [
        'A' => 'LineString',
        'B' => 'Point', 'D' => 'Point', 'E' => 'Point', 'F' => 'Point', 'G' => 'Point',
        'N' => 'Point', 'O' => 'Point', 'P' => 'Point', 'Q' => 'Point',
    ];

    /** Property keys consumed into columns - never stored as attributes. */
    private const array CONSUMED_KEYS = ['n', 'name', 'prov', 'source', 'ref'];

    /** Same-level overlap above this fraction of the smaller area is a bad import; at or below it is a digitization sliver. */
    private const float REGION_OVERLAP_TOLERANCE = 0.001;

    /**
     * Rows the duplicate guard held out, reported after the transaction.
     *
     * Named one by one, never counted: "skipped 4 duplicates" tells an operator
     * nothing they can check, and a wrong skip would look exactly like a right
     * one.
     *
     * @var list<string>
     */
    private array $duplicateSkips = [];

    /** @var list<string> photos PhotoValidator refused, one line each */
    private array $droppedPhotos = [];

    public function __construct(
        private readonly Connection $db,
        private readonly AttributeVocabulary $vocabulary,
        private readonly SurfaceProfiler $surfaces,
        private readonly BaseLocationService $baseLocations,
        private readonly DuplicateGuard $duplicates,
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
            // docs/specs/map-and-search.md §4.5 — re-derive bases in this transaction (no queue).
            $rederived = $this->baseLocations->rederiveAll();
            $io->text(sprintf('Re-derived base areas for %d rider(s).', $rederived));
            // Surfaces after membership, same transaction.
            $surfaced = $this->surfaces->recomputeAll();
            $this->db->commit();
        } catch (\InvalidArgumentException|\JsonException|DBALException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $this->droppedPhotos) {
            $io->note(sprintf(
                "Left off %d photo(s) PhotoValidator refused (the rows are imported without them):\n  %s",
                \count($this->droppedPhotos),
                implode("\n  ", $this->droppedPhotos),
            ));
        }
        if ([] !== $this->duplicateSkips) {
            $io->note(sprintf(
                "Skipped %d feature(s) that duplicate a row already in the catalog:\n  %s",
                \count($this->duplicateSkips),
                implode("\n  ", $this->duplicateSkips),
            ));
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
            // docs/specs/map-and-search.md §4.5 — country_code required (unstamped region is invisible to country-scoped curators).
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

        // docs/specs/map-and-search.md §4.5 — same-level tessellation; reject overlap here, smallest-area-wins if one slips through.
        $this->assertRegionsTessellate();

        return $count;
    }

    /**
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
        // docs/specs/map-and-search.md §4.5 — same (country, admin_level) must tessellate; IS NOT DISTINCT FROM keeps NULL-level rows in the guard. Cross-level containment is expected.
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
                // Derive serviceKind from legacy `t` when the artifact lacks it.
                if (ItemType::BikeServices === $type && !isset($attributes['serviceKind'])) {
                    $kind = ServiceKind::fromLegacyLabel(\is_string($attributes['t'] ?? null) ? $attributes['t'] : null);
                    if (null !== $kind) {
                        $attributes['serviceKind'] = $kind->value;
                    }
                }
                $this->vocabulary->assertValid($type, $attributes);
                $attributes = self::foldSurfacePhoto($attributes);

                [$source, $ref] = $this->resolveSourceRef($props, $file);

                // OSM pools emit `n`; climbs/surface exporters emit `name`.
                $name = (string) ($props['n'] ?? $props['name'] ?? '');

                $pin = 'Point' === $geometry['type'] ? $geometry['coordinates'] : [];
                $sifted = PhotoValidator::sift($attributes, PhotoPlace::of($letter, $pin[1] ?? null, $pin[0] ?? null));
                $attributes = $sifted['attributes'];
                foreach ($sifted['dropped'] as $dropped) {
                    $this->droppedPhotos[] = sprintf('%s: %s', '' === $name ? $source.':'.$ref : $name, null !== $dropped['verdict']->reason ? $dropped['verdict']->reason->value : 'refused');
                }
                $geomJson = json_encode($geometry, \JSON_THROW_ON_ERROR);

                /* One place, one row (catalog-data-model.md §5). A harvest run
                   under one source must not add a second pin to a place another
                   source already holds: read-time dedupe matches by ref, so it
                   is blind to a PIVOT hotel and an OSM hotel being one hotel. */
                $held = $this->duplicates->existingAtGeometry($letter, $name, $geomJson, $source.':'.$ref);
                if (null !== $held) {
                    $incoming = ItemSource::tryFrom($source);
                    $this->duplicateSkips[] = null === $incoming
                        ? sprintf('%s — already held by #%d', $name, $held['id'])
                        : DuplicateGuard::explain($name, $incoming, $held);
                    continue;
                }

                $prov = (string) ($props['prov'] ?? '');
                $this->db->executeStatement(
                    ItemUpsert::SQL,
                    [
                        'letter' => $letter,
                        'name' => $name,
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

    /**
     * A road surface artifact names its Commons photo in four flat keys; the
     * row stores the one `photo` entry every place carries
     * (CommonsFile::hotlinkEntry()), so PhotoValidator judges it below and
     * `app:media:localise-commons` can make it ours. The flat keys are not
     * stored.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private static function foldSurfacePhoto(array $attributes): array
    {
        $file = $attributes['photoFile'] ?? null;
        if (\is_string($file) && '' !== trim($file) && !\array_key_exists('photo', $attributes)) {
            $attributes['photo'] = CommonsFile::hotlinkEntry(
                trim($file),
                \is_string($attributes['photoCredit'] ?? null) ? $attributes['photoCredit'] : '',
                \is_string($attributes['photoUser'] ?? null) ? $attributes['photoUser'] : null,
                \is_string($attributes['photoLicense'] ?? null) ? $attributes['photoLicense'] : '',
            );
        }
        unset($attributes['photoFile'], $attributes['photoCredit'], $attributes['photoUser'], $attributes['photoLicense']);

        return $attributes;
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
            // docs/specs/route-domain.md §9 — harvest scalar `summer` → stored list `['Summer']`.
            $route['attributes'] = $this->normalizeSeason($route['attributes']);
            // A route is letter R; its pin is not stored yet, and R has no pin check.
            $sifted = PhotoValidator::sift($route['attributes'], PhotoPlace::route(null, null));
            $route['attributes'] = $sifted['attributes'];
            foreach ($sifted['dropped'] as $dropped) {
                $this->droppedPhotos[] = sprintf('%s: %s', $route['name'], null !== $dropped['verdict']->reason ? $dropped['verdict']->reason->value : 'refused');
            }
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
        // docs/specs/catalog-data-model.md §6 — smallest-area-wins; uncontained rows stay NULL.
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

        // docs/specs/catalog-data-model.md §6 — heat points need rid; unstamped would render in every scope or none.
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

    /** Derived adj: never authored. Empty array, never NULL, when a region touches nothing. */
    private function recomputeAdjacency(): void
    {
        $this->db->executeStatement(self::adjacencySql());
    }

    /**
     * Neighbours are OPERATIONAL regions only, on both sides (catalog-data-model.md §2.4).
     *
     * Every region intersects its own country outline, so the unfiltered
     * version made the level-2 row a neighbour of all twelve Dutch provinces -
     * and the spotlight, which punches its clear hole through the union of
     * region + neighbours, then lit the whole Netherlands instead of North
     * Holland and its four real neighbours (owner 2026-08-24).
     *
     * The `a` predicate sits INSIDE the subquery deliberately: for a
     * non-operational row it matches nothing, so COALESCE writes the empty
     * array. A country outline ends up with no neighbours, which is the truth
     * about a row that is not a scope.
     *
     * Shared with Version20260824120000, so a fix here cannot drift from what
     * deployed databases were backfilled with.
     */
    public static function adjacencySql(): string
    {
        return 'UPDATE region a SET adj = COALESCE((
                SELECT array_agg(b.id ORDER BY b.id)
                FROM region b
                WHERE b.id <> a.id AND ST_Intersects(a.geom, b.geom)
                  AND '.OperationalRegions::predicate('b').'
                  AND '.OperationalRegions::predicate('a').'
             ), ARRAY[]::int[])
             WHERE a.geom IS NOT NULL';
    }

    /** Derived ranking outline, never authored. Empty array, never NULL. */
    private function recomputeOutlines(): void
    {
        $this->db->executeStatement(self::OUTLINE_SQL);
    }

    /**
     * Compute part area once (correlated ST_Area of the whole geom is quadratic). Must match Version20260727120000.
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
