<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\ItemUpsert;
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
 * Seeds well-known mountain passes from the REVIEWED climb-sides artifact.
 *
 * Same split as {@see SeedWikidataPlacesCommand}: the harvesting, the judging
 * and the naming happen in `tools/wikimedia/` and land in a file a human reads;
 * this imports that file. What ships is a list somebody reviewed, and a re-run
 * in six months produces the same catalogue rather than whatever the router
 * said that morning.
 *
 * **One item per SIDE, not per pass**, which is the whole point of the harvest.
 * Stelvio from Prato and Stelvio from Bormio are different climbs that happen
 * to end in the same place, and a catalogue that holds one of them is missing a
 * climb rather than being tidy. Sides are therefore named by where they start -
 * how riders name them - and identified by `wikidata:<qid>:<side>`, so a re-run
 * upserts each side onto itself.
 *
 * **Only what the review passed.** The default is `KEEP` alone; `--verdict` can
 * widen it, and CHECK rows are exactly the ones a person should have looked at
 * first (a side cut short at a terrace, a road that never reaches a col because
 * the col is a walking pass). DROP is refused outright rather than offered,
 * because those rows are hiking trails and a via ferrata: nothing about a later
 * decision should be able to let them in through this door.
 *
 * Rows enter at `state = unverified` like every other seeded row - being famous
 * is not the same as having been ridden by somebody who then said so - and the
 * curator-edit shield in {@see ItemUpsert} means a re-run leaves a corrected
 * row alone.
 *
 * **This writes no gradients.** `length`, `gain`, `avgGradient`, `maxGradient`
 * and the profile are measured from the drawn line by `app:climbs:recompute`,
 * which stays the only thing in the project that writes them
 * (climb-elevation.md §7). Run it after this, or the rows carry a line and no
 * numbers.
 *
 * @see docs/specs/climb-elevation.md §7a
 *
 * @api Console entry point (ops seeding tool).
 */
#[AsCommand(
    name: 'app:catalog:seed-climbs',
    description: 'Seed reviewed mountain-pass climbs from a tools/wikimedia climb-review artifact',
)]
final class SeedClimbsCommand extends Command
{
    /**
     * The surface every seeded side claims. Not a guess and not a default:
     * `climb_audit.py` traced each line against the road graph and every KEEP
     * came back 0% unpaved, so this is the measurement being written down. A
     * row whose trace disagreed is refused below rather than seeded with a
     * surface it does not have.
     */
    private const string SURFACE = 'Asphalt';

    public function __construct(
        private readonly Connection $db,
        private readonly AttributeVocabulary $vocabulary,
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
        $skipped = ['regionless' => [], 'unpaved' => [], 'duplicate-ref' => []];
        $seenRefs = [];

        /* TWO PASSES, because a name cannot be judged alone. Two sides of one
           col whose feet geocoded to nothing usable are both called "Wurzen
           Pass", and the second would upsert over a row it is not - a silent
           loss. So every accepted row is collected first, then the batch is
           made unique, then it is written. */
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

            /* The trace is the surface claim. A row whose line is not
               effectively fully paved is refused rather than written with
               `surface: Asphalt`, because a wrong attribute on a rider-facing
               card is worse than a missing climb. */
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

            /* The line is stored summit-first by the harvester (it walked
               DOWN from the col). A climb is ridden the other way, and
               ClimbProfiler measures from the first point, so it is
               reversed here rather than in six places downstream. */
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
            // The TIDIED place, so the Approach row and the name agree.
            // A card reading "Pitkin County" under a name that does not
            // mention it is two answers to one question.
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
                if (!$dryRun) {
                    $this->db->executeStatement(ItemUpsert::SQL, [
                        'letter' => 'B',
                        'name' => $name,
                        // The SUMMIT, matching every measured climb already in
                        // the catalogue. The line's own end rather than
                        // Wikidata's coordinate: the profile is measured to
                        // where the road stops, so the pin and the numbers
                        // describe the same point.
                        'geom' => json_encode(
                            ['type' => 'Point', 'coordinates' => [(float) $sLng, (float) $sLat]],
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
     * The stable identity of one SIDE.
     *
     * The Q-id alone is the pass, and a pass has more than one climb in it, so
     * the side index rides along. A col Wikidata does not know falls back to a
     * slug of its name plus the index - still stable across runs, because both
     * come from the artifact rather than from row order.
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
     * What a rider calls this climb.
     *
     * "Stelvio Pass" names a col; "Stelvio Pass from Prato" names a climb, and
     * the second is what somebody planning a ride is looking for. The suffix is
     * added whenever the foot is known, INCLUDING for a pass with only one
     * harvested side: a second side found next year must not force the first
     * one to be renamed, and a name that changes under a rider is worse than a
     * name that is slightly long.
     *
     * The raw geocode is kept in the artifact and tidied here, because the two
     * jobs are different: the artifact should record what OSM actually said,
     * and a rider should not read it verbatim. Three things needed fixing on
     * the first run - a multilingual blob ("Cave del Predil / Raibl / Rabelj /
     * Rabil"), an administrative area standing in for a town ("from Pitkin
     * County"), and a foot named after the pass itself ("Sir Lowry's Pass from
     * Sir Lowry's Pass").
     *
     * @param array<string, mixed> $row
     */
    private function nameFor(array $row): string
    {
        $name = trim((string) $row['name']);
        $place = self::tidyPlace((string) ($row['foot_place'] ?? ''));

        // A foot whose name is already inside the pass name adds nothing: the
        // reader learns the same word twice and the row gets longer.
        if ('' === $place || str_contains(mb_strtolower($name), mb_strtolower($place))) {
            return $name;
        }

        return sprintf('%s from %s', $name, $place);
    }

    /**
     * A settlement name a rider would use, or '' when there is none.
     *
     * Administrative areas are dropped rather than shortened. "from Pitkin
     * County" is not how anyone describes where a climb starts, and a wrong
     * name is worse than no name - the collision pass below can still tell two
     * sides apart by their road.
     */
    private static function tidyPlace(string $raw): string
    {
        // OSM stores a bilingual border settlement as one slash-joined string.
        // The first is the one on the side the road comes from.
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
     * Makes every name in the batch unique, preferring a fact over a number.
     *
     * Two sides of one col whose feet geocoded to nothing usable would both be
     * called "Independence Pass", and the second would upsert over a row it is
     * not - a climb quietly lost. Something has to tell them apart, and what
     * that something IS matters: a rider reading "(CO 82)" or "(14.0 km)"
     * learns which climb this is, where "(2)" only tells them somebody gave up.
     *
     * So: the road when the colliding rows run on DIFFERENT roads, and the
     * length when they share one - which is the Independence Pass case, both
     * sides of it being CO 82.
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

    /**
     * The operational region containing a point, or null.
     *
     * Asked BEFORE the insert, exactly as the Wikidata seeder does it, so a
     * climb outside every onboarded region is never written at all rather than
     * written and then invisible to every region-scoped query.
     */
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
                WHERE it.letter = 'B' AND it.source = 'wikidata'
               ) sub
              WHERE i.id = sub.iid AND i.region_id IS DISTINCT FROM sub.rid",
        );
    }
}
