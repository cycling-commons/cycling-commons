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
 * Seeds the scenic viewpoints and historical places harvested from Wikidata.
 *
 * Gives a freshly onboarded country something worth looking at on day one. A
 * region page whose only content is "0 verified items" tells a rider the
 * project is empty, not that their area is new — and the two layers a stranger
 * to a country most wants are exactly the two hardest for local riders to
 * bootstrap: what is worth stopping for, and what is worth riding past.
 *
 * **Reads a committed artifact, never the network.** `tools/wikimedia/
 * country_places.py` does the harvesting and the licence checking, and writes
 * `places-<cc>.json` for a human to read. This command imports that file. The
 * split matters: what ships is a list somebody reviewed, not whatever Wikidata
 * returned the minute the seed ran, and a re-run in six months produces the
 * same catalogue rather than silently drifting.
 *
 * Rows land as `source = wikidata`, `source_ref = wikidata:<Q-id>` — a stable
 * identity that upserts cleanly, and provenance a rider can see on the drawer's
 * Source line. They enter at `state = unverified` like every other seeded row:
 * being famous is not the same as having been checked by somebody who rode
 * there, and the verification funnel is what tells them apart.
 *
 * The curator-edit shield applies as everywhere else ({@see ItemUpsert}) — once
 * somebody has corrected one of these, a re-run leaves it alone.
 *
 * @see docs/specs/catalog-data-model.md §3
 *
 * @api Console entry point (ops seeding tool).
 */
#[AsCommand(
    name: 'app:catalog:seed-wikidata',
    description: 'Seed scenic + historical places from a reviewed tools/wikimedia/out artifact',
)]
final class SeedWikidataPlacesCommand extends Command
{
    /** Layer key in the artifact -> the catalogue type it seeds. */
    private const array LAYERS = [
        'scenic' => ItemType::ScenicViews,
        'history' => ItemType::HistoryCulture,
    ];

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
            ->addArgument('dir', InputArgument::REQUIRED, 'Directory holding places-<cc>.json artifacts')
            ->addOption('country', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only these countries (default: every artifact in the directory)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be seeded, write nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = (string) $input->getArgument('dir');
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var list<string> $only */
        $only = array_map(strtolower(...), $input->getOption('country'));

        $files = glob(rtrim($dir, '/').'/places-*.json') ?: [];
        if ([] === $files) {
            $io->error(sprintf('No places-*.json artifacts in %s — run tools/wikimedia/country_places.py first.', $dir));

            return Command::INVALID;
        }

        $counts = [];
        try {
            $this->db->beginTransaction();
            foreach ($files as $file) {
                $cc = strtolower(substr(basename($file, '.json'), \strlen('places-')));
                if ([] !== $only && !\in_array($cc, $only, true)) {
                    continue;
                }

                /** @var array{country?: string, scenic?: list<array<string, mixed>>, history?: list<array<string, mixed>>} $data */
                $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
                $country = strtoupper((string) ($data['country'] ?? $cc));

                foreach (self::LAYERS as $layer => $type) {
                    foreach ($data[$layer] ?? [] as $place) {
                        $this->seed($place, $type, $country, $dryRun);
                        $counts[$country][$type->letter()] = ($counts[$country][$type->letter()] ?? 0) + 1;
                    }
                }
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

        if ([] === $counts) {
            $io->warning('Nothing matched — check --country.');

            return Command::SUCCESS;
        }

        ksort($counts);
        foreach ($counts as $country => $byLetter) {
            ksort($byLetter);
            $parts = [];
            foreach ($byLetter as $letter => $n) {
                $parts[] = sprintf('%s: %d', $letter, $n);
            }
            $io->writeln(sprintf('  %s — %s', $country, implode(', ', $parts)));
        }
        $io->success(sprintf(
            '%s %d place(s) across %d country/ies.',
            $dryRun ? 'Would seed' : 'Seeded',
            array_sum(array_map(array_sum(...), $counts)),
            \count($counts),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $place
     */
    private function seed(array $place, ItemType $type, string $country, bool $dryRun): void
    {
        /** @var array{file: string, credit: string, user: ?string, license: string} $photo */
        $photo = $place['photo'];

        $attributes = [
            'type' => (string) $place['type'],
            'photo' => self::commonsPhoto($photo['file'], $photo['credit'], $photo['user'], $photo['license']),
        ];
        // Wikidata's one-line description, when it has one. Short, factual and
        // already the thing a reader wants first — but it is genuinely often
        // absent, and an empty note renders as an empty row.
        if ('' !== (string) ($place['note'] ?? '')) {
            $attributes['note'] = (string) $place['note'];
        }

        $this->vocabulary->assertValid($type, $attributes);

        if ($dryRun) {
            return;
        }

        $this->db->executeStatement(ItemUpsert::SQL, [
            'letter' => $type->letter(),
            'name' => (string) $place['name'],
            'geom' => json_encode(
                ['type' => 'Point', 'coordinates' => [(float) $place['lng'], (float) $place['lat']]],
                \JSON_THROW_ON_ERROR,
            ),
            'cc' => $country,
            // Left to the membership recompute, which derives region from the
            // geometry — a country-level import has no business guessing which
            // subdivision a point falls in.
            'sub' => null,
            'state' => 'unverified',
            'source' => 'wikidata',
            'ref' => 'wikidata:'.$place['qid'],
            'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    /**
     * Stamps each seeded place with the region that contains it.
     *
     * Smallest-area-wins on overlap — the SAME deterministic rule every other
     * membership writer uses (ImportCatalogCommand, SeedManualCatalogCommand,
     * RegionResolver, pipeline/coverage/load.py), so a Wikidata place cannot
     * land in a different region than an identically-located imported item.
     * Smallest-area is also what keeps these off the L2 country outlines, which
     * contain every point in the country and would otherwise win nothing but
     * would still be candidates.
     *
     * Without this the rows import with `region_id` NULL, which makes them
     * invisible to every region-scoped query — a scenic pin nobody can find by
     * looking at the region it is in.
     */
    private function recomputeMembership(): void
    {
        $this->db->executeStatement(
            "UPDATE item SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (i.id) i.id AS item_id, r.id AS region_id
                FROM item i JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(i.geom))
                WHERE i.source = 'wikidata'
                ORDER BY i.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE item.id = m.item_id",
        );
    }

    /**
     * Wikimedia Commons photo record — the same shape and the same URL
     * construction {@see SeedManualCatalogCommand::wc()} produces, so the
     * drawer cannot tell a seeded photo from a hand-authored one.
     *
     * @return array{sm: string, lg: string, credit: string, creditUrl: string, license: string, source: string}
     */
    private static function commonsPhoto(string $file, string $credit, ?string $user, string $license): array
    {
        $enc = str_replace(['%21', '%27', '%28', '%29', '%2A'], ['!', "'", '(', ')', '*'], rawurlencode($file));
        $page = str_replace(' ', '_', $file);

        return [
            'sm' => "https://commons.wikimedia.org/wiki/Special:FilePath/{$enc}?width=520",
            'lg' => "https://commons.wikimedia.org/wiki/Special:FilePath/{$enc}?width=1400",
            'credit' => $credit,
            'creditUrl' => null !== $user && '' !== $user
                ? 'https://commons.wikimedia.org/wiki/User:'.str_replace(' ', '_', $user)
                : '',
            'license' => $license,
            'source' => "https://commons.wikimedia.org/wiki/File:{$page}",
        ];
    }
}
