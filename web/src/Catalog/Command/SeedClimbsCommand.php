<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\DuplicateGuard;
use App\Catalog\Import\ItemUpsert;
use App\Catalog\ItemSource;
use App\Catalog\ItemType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed reviewed climb-sides. One item per side, not per pass. Writes no gradients — those are measured.
 *
 * @see docs/specs/climb-elevation.md §7a
 *
 * @api
 */
#[AsCommand(
    name: 'app:catalog:seed-climbs',
    description: 'Seed reviewed mountain-pass climbs from a tools/wikimedia climb-review artifact',
)]
final class SeedClimbsCommand extends Command
{
    /** KEEP rows are 0% unpaved; refuse a row whose trace disagrees rather than stamp Asphalt. */
    private const string SURFACE = 'Asphalt';

    public function __construct(
        private readonly Connection $db,
        private readonly AttributeVocabulary $vocabulary,
        private readonly DuplicateGuard $duplicates,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('artifact', InputArgument::REQUIRED, 'Path to climb-review.json')
            ->addOption('verdict', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Which verdicts to seed (default: KEEP). DROP is never accepted.')
            ->addOption('country', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only these countries')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be seeded, write nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var list<string> $verdicts */
        $verdicts = array_map(strtoupper(...), $input->getOption('verdict'));
        if ([] === $verdicts) {
            $verdicts = ['KEEP'];
        }
        if (\in_array('DROP', $verdicts, true)) {
            $io->error('DROP rows are hiking trails, a via ferrata and a railway pass. They are not seedable.');

            return Command::INVALID;
        }
        /** @var list<string> $only */
        $only = array_map(strtoupper(...), $input->getOption('country'));

        $path = (string) $input->getArgument('artifact');
        $raw = @file_get_contents($path);
        if (false === $raw) {
            $io->error(sprintf('Cannot read %s — run tools/wikimedia/climb_audit.py --json-out first.', $path));

            return Command::INVALID;
        }

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Not JSON: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $seeded = [];
        $skipped = ['regionless' => [], 'unpaved' => [], 'duplicate-ref' => [], 'duplicate-place' => []];
        $seenRefs = [];

        /* Collect then write: two sides of one col can share a name and would otherwise collide. */
        $accepted = [];
        foreach ($rows as $row) {
            if (!\in_array((string) ($row['verdict'] ?? ''), $verdicts, true)) {
                continue;
            }
            $cc = strtoupper((string) $row['cc']);
            if ([] !== $only && !\in_array($cc, $only, true)) {
                continue;
            }
            /** @var list<array{0: float, 1: float}> $line */
            $line = $row['line'];
            if (\count($line) < 2) {
                continue;
            }

            /* Refuse a line that is not effectively fully paved rather than write `surface: Asphalt`. */
            $unpaved = (float) ($row['trace']['unpaved_pct'] ?? 100.0);
            if ($unpaved > 5.0) {
                $skipped['unpaved'][] = sprintf('%s (%s, %.1f%% unpaved)', $row['name'], $cc, $unpaved);
                continue;
            }

            $ref = $this->refFor($row);
            if (isset($seenRefs[$ref])) {
                $skipped['duplicate-ref'][] = sprintf('%s (%s) — %s', $row['name'], $cc, $ref);
                continue;
            }

            /* Harvester stores summit-first; reverse so ClimbProfiler measures from the foot. */
            $route = array_reverse($line);
            [$sLat, $sLng] = $line[0];

            if (null === $this->regionFor((float) $sLat, (float) $sLng)) {
                $skipped['regionless'][] = sprintf('%s (%s)', $row['name'], $cc);
                continue;
            }
            $seenRefs[$ref] = true;

            $attributes = [
                'route' => array_map(
                    static fn (array $p): array => [(float) $p[0], (float) $p[1]],
                    $route,
                ),
                'surface' => self::SURFACE,
            ];
            // Tidied place so Approach and the name agree.
            $place = self::tidyPlace((string) ($row['foot_place'] ?? ''));
            if ('' !== $place) {
                $attributes['approach'] = 'From '.$place;
            }
            $this->vocabulary->assertValid(ItemType::Climbs, $attributes);

            $accepted[] = [
                'row' => $row, 'ref' => $ref, 'cc' => $cc, 'attrs' => $attributes,
                'lat' => (float) $sLat, 'lng' => (float) $sLng,
                'name' => $this->nameFor($row),
                'road' => (string) ($row['trace']['road'] ?? ''),
                'km' => (float) $row['length_m'] / 1000,
            ];
        }

        $names = self::deduplicate(array_map(
            static fn (array $a): array => ['name' => $a['name'], 'road' => $a['road'], 'km' => $a['km']],
            $accepted,
        ));

        try {
            $this->db->beginTransaction();
            foreach ($accepted as $i => $entry) {
                $cc = $entry['cc'];
                $name = $names[$i];
                $attributes = $entry['attrs'];
                $sLat = $entry['lat'];
                $sLng = $entry['lng'];
                $ref = $entry['ref'];

                /* One place, one row (catalog-data-model.md §5). A climb the
                   Wallonia harvest already seeded under an OSM ref is the same
                   hill, and read-time dedupe matches by ref, so it cannot see
                   that. Checked in a dry run too, or the dry run would report a
                   number the real run will not produce. */
                $held = $this->duplicates->existing('B', $name, $sLat, $sLng, 'wikidata:'.$ref);
                if (null !== $held) {
                    $skipped['duplicate-place'][] = DuplicateGuard::explain($name, ItemSource::Wikidata, $held);
                    continue;
                }

                if (!$dryRun) {
                    $this->db->executeStatement(ItemUpsert::SQL, [
                        'letter' => 'N',
                        'name' => $name,
                        // Summit = the line's own end, not Wikidata's coordinate.
                        'geom' => json_encode(
                            ['type' => 'Point', 'coordinates' => [$sLng, $sLat]],
                            \JSON_THROW_ON_ERROR,
                        ),
                        'cc' => $cc,
                        'sub' => null,
                        'state' => 'unverified',
                        'source' => 'wikidata',
                        'ref' => $ref,
                        'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                    ]);
                }
                $seeded[$cc][] = $name;
            }

            if ($dryRun) {
                $this->db->rollBack();
            } else {
                $this->recomputeMembership();
                $this->db->commit();
            }
        } catch (\InvalidArgumentException|\JsonException|DBALException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        ksort($seeded);
        foreach ($seeded as $cc => $names) {
            $io->writeln(sprintf('  <info>%s</info> — %d: %s', $cc, \count($names), implode(', ', $names)));
        }
        foreach ($skipped as $why => $list) {
            if ([] !== $list) {
                $io->note(sprintf("Skipped %d (%s):\n  %s", \count($list), $why, implode("\n  ", $list)));
            }
        }
        $total = array_sum(array_map(\count(...), $seeded));
        $io->success(sprintf('%s %d climb side(s).', $dryRun ? 'Would seed' : 'Seeded', $total));
        if (!$dryRun && $total > 0) {
            $io->warning('These rows carry a LINE and no numbers. Run: app:climbs:recompute --write');
        }

        return Command::SUCCESS;
    }

    /**
     * One SIDE: `wikidata:<qid>:<side>` (or a name slug when Wikidata has no Q-id).
     *
     * @param array<string, mixed> $row
     */
    private function refFor(array $row): string
    {
        $index = (int) ($row['side_index'] ?? 0);
        $qid = (string) ($row['qid'] ?? '');
        if ('' !== $qid) {
            return sprintf('wikidata:%s:%d', $qid, $index);
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $row['name'])) ?? '';

        return sprintf('wikidata:%s-%s:%d', strtolower((string) $row['cc']), trim($slug, '-'), $index);
    }

    /**
     * "Pass from Place" when the foot is known, including a one-side harvest so a later second side does not rename it.
     *
     * @param array<string, mixed> $row
     */
    private function nameFor(array $row): string
    {
        $name = trim((string) $row['name']);
        $place = self::tidyPlace((string) ($row['foot_place'] ?? ''));

        // A foot already inside the pass name adds nothing.
        if ('' === $place || str_contains(mb_strtolower($name), mb_strtolower($place))) {
            return $name;
        }

        return sprintf('%s from %s', $name, $place);
    }

    /** Settlement a rider would use, or '' — drop administrative areas rather than shorten them. */
    private static function tidyPlace(string $raw): string
    {
        // OSM bilingual settlement is slash-joined; first is the side the road comes from.
        $place = trim(explode('/', $raw)[0]);
        $admin = ['/\s+District Municipality$/i', '/\s+Municipality$/i', '/\s+District$/i',
            '/\s+County$/i', '/\s+Region$/i', '/^Distrito\s+/i'];
        foreach ($admin as $pattern) {
            if (1 === preg_match($pattern, $place)) {
                return '';
            }
        }

        return $place;
    }

    /**
     * Disambiguate colliding names with road (if they differ) else length — never "(2)".
     *
     * @param list<array{name: string, road: string, km: float}> $names
     *
     * @return list<string>
     */
    private static function deduplicate(array $names): array
    {
        $groups = [];
        foreach ($names as $i => $entry) {
            $groups[$entry['name']][] = $i;
        }

        $out = array_column($names, 'name');
        foreach ($groups as $indexes) {
            if (\count($indexes) < 2) {
                continue;
            }
            $roads = array_unique(array_map(static fn (int $i): string => $names[$i]['road'], $indexes));
            $byRoad = \count($roads) === \count($indexes) && !\in_array('', $roads, true);
            foreach ($indexes as $i) {
                $out[$i] = $byRoad
                    ? sprintf('%s (%s)', $names[$i]['name'], $names[$i]['road'])
                    : sprintf('%s (%.1f km)', $names[$i]['name'], $names[$i]['km']);
            }
        }

        return $out;
    }

    /** Containing operational region, or null. Asked before insert so a regionless climb is never written. */
    private function regionFor(float $lat, float $lng): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT r.id FROM region r
              WHERE ST_Contains(r.geom, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326))
                AND r.admin_level IS NOT DISTINCT FROM (
                    SELECT MAX(r2.admin_level) FROM region r2 WHERE r2.country_code = r.country_code
                )
              ORDER BY r.area_km2 ASC NULLS LAST, r.id ASC
              LIMIT 1',
            ['lat' => $lat, 'lng' => $lng],
        );

        return false === $id ? null : (int) $id;
    }

    /** Smallest-area-wins, the same deterministic rule every membership writer uses. */
    private function recomputeMembership(): void
    {
        $this->db->executeStatement(
            "UPDATE item i SET region_id = sub.rid
               FROM (
                 SELECT it.id AS iid, (
                   SELECT r.id FROM region r
                    WHERE ST_Contains(r.geom, it.geom)
                      AND r.admin_level IS NOT DISTINCT FROM (
                          SELECT MAX(r2.admin_level) FROM region r2 WHERE r2.country_code = r.country_code
                      )
                    ORDER BY r.area_km2 ASC NULLS LAST, r.id ASC
                    LIMIT 1
                 ) AS rid
                 FROM item it
                WHERE it.letter = 'N' AND it.source = 'wikidata'
               ) sub
              WHERE i.id = sub.iid AND i.region_id IS DISTINCT FROM sub.rid",
        );
    }
}
