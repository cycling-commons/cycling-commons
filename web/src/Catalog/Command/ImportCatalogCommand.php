<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\ClimbFoot;
use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\DuplicateGuard;
use App\Catalog\Import\ItemUpsert;
use App\Catalog\Import\ProvinceMap;
use App\Catalog\ItemSource;
use App\Catalog\ItemType;
use App\Catalog\PlaceKind;
use App\Catalog\RegionDerivations;
use App\Catalog\RegionUpserter;
use App\Catalog\ServiceKind;
use App\Media\Commons\CommonsFile;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
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
        private readonly RegionUpserter $regionUpserter,
        private readonly RegionDerivations $derivations,
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
            $this->derivations->shapes();
            $items = $this->importItemLayers($dir, $io);
            $routes = $this->importRoutes($dir, $io);
            $heat = $this->importHeat($dir, $io);
            $derived = $this->derivations->dependents();
            $io->text(sprintf('Re-derived base areas for %d rider(s).', $derived['rederived']));
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

        $io->success(sprintf('Catalog import: %d region(s), %d item(s) upserted, %d route(s), %d heat point(s), %d region-assigned, %d route surface profile(s).', $regions, $items, $routes, $heat, $derived['assigned'], $derived['surfaced']));

        return Command::SUCCESS;
    }

    private function importRegions(string $dir, SymfonyStyle $io): int
    {
        $count = 0;
        foreach (glob($dir.'/region-*.geojson') ?: [] as $file) {
            /** @var array{properties: array<string, mixed>, geometry: array<string, mixed>} $feature */
            $feature = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            // docs/specs/map-and-search.md §4.5: country_code required (unstamped region is invisible to country-scoped curators).
            $this->regionUpserter->upsert($feature['properties'], json_encode($feature['geometry'], \JSON_THROW_ON_ERROR), basename($file));
            ++$count;
            $io->writeln(sprintf('  region %s', \is_string($feature['properties']['slug'] ?? null) ? $feature['properties']['slug'] : '?'));
        }

        // docs/specs/map-and-search.md §4.5: same-level tessellation; reject overlap here, smallest-area-wins if one slips through.
        $this->regionUpserter->assertTessellates();

        return $count;
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
                // A typed letter's `t` names its kind when the artifact gives no Type (osm-data-architecture.md §5a).
                if (\in_array($type->letter(), PlaceKind::TYPED_LETTERS, true) && !isset($attributes['type'])) {
                    $kind = PlaceKind::fromLabel($type->letter(), \is_string($attributes['t'] ?? null) ? $attributes['t'] : null);
                    if (null !== $kind) {
                        $attributes['type'] = $kind;
                    }
                }
                $this->vocabulary->assertValid($type, $attributes);
                $attributes = self::foldSurfacePhoto($attributes);

                [$source, $ref] = $this->resolveSourceRef($props, $file);

                // OSM pools emit `n`; climbs/surface exporters emit `name`.
                $name = (string) ($props['n'] ?? $props['name'] ?? '');

                // A climb's point is the foot of its line (ClimbFoot), not the point the exporter carried.
                $foot = 'N' === $letter ? ClimbFoot::of($attributes['route'] ?? null) : null;
                if (null !== $foot) {
                    $geometry = ['type' => 'Point', 'coordinates' => [$foot[1], $foot[0]]];
                }

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
                        'cc' => $this->countryAt($geomJson),
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

    /** Country of the smallest region holding the feature, '' outside every region (SpatialResolver's rule). */
    private function countryAt(string $geomJson): string
    {
        $cc = $this->db->fetchOne(
            'SELECT country_code FROM region
              WHERE ST_Contains(geom, ST_PointOnSurface(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)))
              ORDER BY area_km2 ASC NULLS LAST, id ASC LIMIT 1',
            ['g' => $geomJson],
        );

        return \is_string($cc) ? $cc : '';
    }

    /** Kept for Version20260824120000 and RegionAdjacencyTest; the rule lives in RegionDerivations. */
    public static function adjacencySql(): string
    {
        return RegionDerivations::adjacencySql();
    }

    /** Kept for Version20260727120000; the rule lives in RegionDerivations. */
    public const string OUTLINE_SQL = RegionDerivations::OUTLINE_SQL;
}
