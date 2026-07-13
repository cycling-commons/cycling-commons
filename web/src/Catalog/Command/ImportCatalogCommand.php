<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\ItemUpsert;
use App\Catalog\Import\ProvinceMap;
use App\Catalog\ItemSource;
use App\Catalog\ItemType;
use App\Catalog\SurfaceProfiler;
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
 * — one entity may carry two classifications — routes by (source, source_ref);
 * region membership recomputed every run. Updates never touch lifecycle state.
 *
 * @api Console entry point, invoked by the router of humans (make/CLI).
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

    /** Property keys consumed into columns — never stored as attributes. */
    private const array CONSUMED_KEYS = ['n', 'name', 'prov', 'source', 'ref'];

    public function __construct(
        private readonly Connection $db,
        private readonly AttributeVocabulary $vocabulary,
        private readonly SurfaceProfiler $surfaces,
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
            $items = $this->importItemLayers($dir, $io);
            $routes = $this->importRoutes($dir, $io);
            $heat = $this->importHeat($dir, $io);
            $assigned = $this->recomputeMembership();
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
            /** @var array{properties: array{slug: string, name: string, area_km2?: float|int}, geometry: array<string, mixed>} $feature */
            $feature = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            $this->db->executeStatement(
                'INSERT INTO region (slug, name, geom, area_km2, created_at, updated_at)
                 VALUES (:slug, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :area, NOW(), NOW())
                 ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name, geom = EXCLUDED.geom,
                   area_km2 = EXCLUDED.area_km2,
                   updated_at = CASE WHEN (region.name, ST_AsEWKB(region.geom), region.area_km2)
                                     IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.area_km2)
                                THEN NOW() ELSE region.updated_at END',
                [
                    'slug' => $feature['properties']['slug'],
                    'name' => $feature['properties']['name'],
                    'geom' => json_encode($feature['geometry'], \JSON_THROW_ON_ERROR),
                    'area' => $feature['properties']['area_km2'] ?? null,
                ],
            );
            ++$count;
            $io->writeln(sprintf('  region %s', $feature['properties']['slug']));
        }

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
                continue; // routes.json / heat.json are handled by their own importers (Task 6)
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
                $this->vocabulary->assertValid($type, $attributes);

                [$source, $ref] = $this->resolveSourceRef($props, $file);

                $prov = (string) ($props['prov'] ?? '');
                // Shared upsert; preserves curator-approved edits on re-harvest
                // (#26 — see ItemUpsert).
                $this->db->executeStatement(
                    ItemUpsert::SQL,
                    [
                        'letter' => $letter,
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
                // fixture order is [lat, lng, season] — ST_Point takes (x=lng, y=lat)
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
     * carry: both keys present and non-empty, and `source` a real ItemSource —
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
        $this->db->executeStatement('UPDATE item SET region_id = NULL');
        $assigned = (int) $this->db->executeStatement(
            'UPDATE item SET region_id = r.id FROM region r WHERE ST_Contains(r.geom, ST_PointOnSurface(item.geom))',
        );

        $this->db->executeStatement('UPDATE recommended_route SET region_id = NULL');
        $assigned += (int) $this->db->executeStatement(
            'UPDATE recommended_route SET region_id = r.id FROM region r WHERE ST_Contains(r.geom, ST_PointOnSurface(recommended_route.geom))',
        );

        return $assigned;
    }
}
