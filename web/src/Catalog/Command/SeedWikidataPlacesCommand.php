<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\DuplicateGuard;
use App\Catalog\Import\ItemUpsert;
use App\Catalog\ItemSource;
use App\Catalog\ItemType;
use App\Media\PhotoFacts;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
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
 * Seed scenic/historical places from a reviewed Wikidata artifact. Always unverified.
 *
 * Each place's photo is written only when PhotoValidator shows it on that
 * place; a refused photo is left off (the place is still seeded) and named in
 * the report with the reason. A scenic view needs the photo's `camera` point
 * in the artifact, within reach of the pin.
 *
 * @see docs/specs/photo-uploads.md §5h
 * @see docs/specs/catalog-data-model.md §3
 *
 * @api
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

    /** @var list<string> photos PhotoValidator refused, one line each */
    private array $droppedPhotos = [];

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
        /* First Q-id wins: a border place appears in two country artifacts. */
        $seenRefs = [];
        $duplicates = [];
        /* Skip places in no operational region — a harvest bbox is not onboarded geography. */
        $regionless = [];
        /* Skip places another source already holds — one place, one row. */
        $duplicatePlaces = [];
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
                        $ref = 'wikidata:'.$place['qid'];
                        if (isset($seenRefs[$ref])) {
                            $duplicates[] = sprintf('%s (%s, already seeded for %s)',
                                $place['name'], $country, $seenRefs[$ref]);
                            continue;
                        }
                        if (null === $this->regionFor((float) $place['lat'], (float) $place['lng'])) {
                            $regionless[] = sprintf('%s (%s)', $place['name'], $country);
                            continue;
                        }
                        /* One place, one row: an OSM harvest may already hold it
                           under a different ref, which read-time dedupe (by ref)
                           cannot see. catalog-data-model.md §5. */
                        $held = $this->duplicates->existing(
                            $type->letter(), (string) $place['name'],
                            (float) $place['lat'], (float) $place['lng'], $ref,
                        );
                        if (null !== $held) {
                            $duplicatePlaces[] = DuplicateGuard::explain((string) $place['name'], ItemSource::Wikidata, $held);
                            continue;
                        }
                        $seenRefs[$ref] = $country;
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

        ksort($counts);
        foreach ($counts as $country => $byLetter) {
            ksort($byLetter);
            $parts = [];
            foreach ($byLetter as $letter => $n) {
                $parts[] = sprintf('%s: %d', $letter, $n);
            }
            $io->writeln(sprintf('  %s — %s', $country, implode(', ', $parts)));
        }
        // Reported BEFORE the empty-counts return (test-suite review
        // 2026-08-24): an artifact whose places all fall outside the onboarded
        // regions used to answer "Nothing matched — check --country", which
        // names the wrong cause and sends the operator to fix an option that
        // was never the problem.
        if ([] !== $regionless) {
            $io->note(sprintf(
                "Skipped %d place(s) that fall in no onboarded region:\n  %s",
                \count($regionless),
                implode("\n  ", $regionless),
            ));
        }
        if ([] !== $duplicates) {
            $io->note(sprintf(
                "Skipped %d place(s) that a border shares with another country:\n  %s",
                \count($duplicates),
                implode("\n  ", $duplicates),
            ));
        }
        if ([] !== $this->droppedPhotos) {
            $io->note(sprintf(
                "Left off %d photo(s) PhotoValidator refused (the places are seeded without them):\n  %s",
                \count($this->droppedPhotos),
                implode("\n  ", $this->droppedPhotos),
            ));
        }
        if ([] !== $duplicatePlaces) {
            $io->note(sprintf(
                "Skipped %d place(s) another source already holds:\n  %s",
                \count($duplicatePlaces),
                implode("\n  ", $duplicatePlaces),
            ));
        }

        if ([] === $counts) {
            $io->warning([] === $regionless && [] === $duplicates && [] === $duplicatePlaces
                ? 'Nothing matched — check --country.'
                : 'Nothing was seeded: every place in this artifact was skipped for a reason listed above.');

            return Command::SUCCESS;
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
        /** @var array{file: string, credit: string, user: ?string, license: string, camera?: mixed} $photo */
        $photo = $place['photo'];

        $attributes = [
            'type' => (string) $place['type'],
            'photo' => self::commonsPhoto($photo['file'], $photo['credit'], $photo['user'], $photo['license'], $photo['camera'] ?? null),
        ];
        if ('' !== (string) ($place['note'] ?? '')) {
            $attributes['note'] = (string) $place['note'];
        }

        $sifted = PhotoValidator::sift($attributes, new PhotoPlace($type->letter(), (float) $place['lat'], (float) $place['lng']));
        $attributes = $sifted['attributes'];
        foreach ($sifted['dropped'] as $dropped) {
            $this->droppedPhotos[] = sprintf('%s (%s): %s', (string) $place['name'], $country, $dropped['verdict']->reason?->value ?? 'refused');
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
            // Membership recompute derives region from geometry.
            'sub' => null,
            'state' => 'unverified',
            'source' => 'wikidata',
            'ref' => 'wikidata:'.$place['qid'],
            'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    /** Containing operational region, or null. Asked before insert so a regionless place is never written. */
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

    /** docs/specs/catalog-data-model.md §6 — smallest-area-wins; scoped to source=wikidata. */
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
     * @return array<string, mixed> sm, lg, credit, creditUrl, license, source, and cameraAt when the artifact names a camera
     */
    private static function commonsPhoto(string $file, string $credit, ?string $user, string $license, mixed $camera): array
    {
        $enc = str_replace(['%21', '%27', '%28', '%29', '%2A'], ['!', "'", '(', ')', '*'], rawurlencode($file));
        $page = str_replace(' ', '_', $file);

        $photo = [
            'sm' => "https://commons.wikimedia.org/wiki/Special:FilePath/{$enc}?width=520",
            'lg' => "https://commons.wikimedia.org/wiki/Special:FilePath/{$enc}?width=1400",
            'credit' => $credit,
            'creditUrl' => null !== $user && '' !== $user
                ? 'https://commons.wikimedia.org/wiki/User:'.str_replace(' ', '_', $user)
                : '',
            'license' => $license,
            'source' => "https://commons.wikimedia.org/wiki/File:{$page}",
        ];
        $cameraAt = PhotoFacts::camera($camera);
        if (null !== $cameraAt) {
            $photo['cameraAt'] = $cameraAt;
        }

        return $photo;
    }
}
