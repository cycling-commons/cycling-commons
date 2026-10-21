<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\CatalogField;
use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\Import\DuplicateGuard;
use App\Catalog\Import\ItemUpsert;
use App\Catalog\Import\OutboundLinks;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\OperationalRegions;
use App\Catalog\PlaceKind;
use App\Media\Commons\CommonsFile;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use Doctrine\DBAL\ArrayParameterType;
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
 * Seed the cycling history places from tools/wikimedia/out/cycling-history.json as `source=manual` Q rows.
 *
 * The stories are ours, so the rows are hand-curated: unverified, unassigned,
 * idempotent on `manual:history-<slug>`. The list is world wide; a place in no
 * onboarded region is skipped and named, and seeds once its country is
 * onboarded. A row takes the artifact's `osmRef`, so it stands in for the OSM
 * pin of the same object, its `web` and Wikipedia `links`
 * (catalog-data-model.md §7), and its Commons photo when PhotoValidator shows
 * it on that place (photo-uploads.md §5h).
 *
 * @see docs/specs/catalog-data-model.md §5
 *
 * @api
 */
#[AsCommand(
    name: 'app:catalog:seed-cycling-history',
    description: 'Seed the cycling history places (a story per place) from a reviewed tools/wikimedia/out artifact',
)]
final class SeedCyclingHistoryCommand extends Command
{
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
            ->addArgument('file', InputArgument::REQUIRED, 'The cycling-history.json artifact')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be seeded, write nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($file)) {
            $io->error(sprintf('No artifact at %s: run tools/wikimedia/cycling_history.py first.', $file));

            return Command::INVALID;
        }

        $type = ItemType::HistoryCulture;
        $counts = [];
        $regionless = [];
        $held = [];
        $droppedPhotos = [];
        $seeded = [];
        try {
            /** @var array{places?: list<array<string, mixed>>} $data */
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

            $this->db->beginTransaction();
            foreach ($data['places'] ?? [] as $place) {
                $row = self::row($place);
                $label = sprintf('%s (%s)', $row['name'], $row['cc']);
                if (!$this->inOperationalRegion($row['lat'], $row['lng'])) {
                    $regionless[] = $label;
                    continue;
                }
                $holder = $this->holder($row);
                if (null !== $holder) {
                    $held[] = $holder;
                    continue;
                }

                $attributes = array_filter(
                    ['type' => $row['kind'], 'cyclingStory' => $row['story'], 'year' => $row['year'], 'web' => $row['web'], 'links' => $row['links'], 'photo' => $row['photo']],
                    static fn (mixed $v): bool => null !== $v,
                );
                $sifted = PhotoValidator::sift($attributes, new PhotoPlace($type->letter(), $row['lat'], $row['lng']));
                $attributes = $sifted['attributes'];
                foreach ($sifted['dropped'] as $dropped) {
                    $droppedPhotos[] = sprintf('%s: %s', $label, null !== $dropped['verdict']->reason ? $dropped['verdict']->reason->value : 'refused');
                }
                if (\is_array($attributes['photo'] ?? null)) {
                    $attributes['photo'] = $this->ownCopyOf($row['ref'], $attributes['photo']);
                }
                $this->vocabulary->assertValid($type, $attributes);
                $seeded[] = $row['ref'];
                $counts[$row['cc']] = ($counts[$row['cc']] ?? 0) + 1;
                if ($dryRun) {
                    continue;
                }

                $this->db->executeStatement(ItemUpsert::SQL, [
                    'letter' => $type->letter(),
                    'name' => $row['name'],
                    'geom' => json_encode(['type' => 'Point', 'coordinates' => [$row['lng'], $row['lat']]], \JSON_THROW_ON_ERROR),
                    'cc' => $row['cc'],
                    // Membership recompute derives region from geometry.
                    'sub' => null,
                    'state' => 'unverified',
                    'source' => ItemSource::Manual->value,
                    'ref' => $row['ref'],
                    'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR),
                ]);
                $this->claimOsmRef($row['ref'], $row['osmRef']);
            }
            if ($dryRun) {
                $this->db->rollBack();
            } else {
                $this->recomputeMembership($seeded);
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
        foreach ($counts as $country => $n) {
            $io->writeln(sprintf('  %s: %d', $country, $n));
        }
        if ([] !== $regionless) {
            $io->note(sprintf(
                "Skipped %d place(s) that fall in no onboarded region (they seed once their country is onboarded):\n  %s",
                \count($regionless),
                implode("\n  ", $regionless),
            ));
        }
        if ([] !== $held) {
            $io->note(sprintf(
                "Skipped %d place(s) another row already holds (add the story to that row instead):\n  %s",
                \count($held),
                implode("\n  ", $held),
            ));
        }
        if ([] !== $droppedPhotos) {
            $io->note(sprintf(
                "Left off %d photo(s) PhotoValidator refused (the places are seeded without them):\n  %s",
                \count($droppedPhotos),
                implode("\n  ", $droppedPhotos),
            ));
        }
        if ([] === $counts) {
            $io->warning('Nothing was seeded.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s %d place(s) across %d country/ies.',
            $dryRun ? 'Would seed' : 'Seeded',
            array_sum($counts),
            \count($counts),
        ));

        return Command::SUCCESS;
    }

    /**
     * One artifact entry, checked. A bad entry fails the whole run: the artifact is a reviewed file, not a feed.
     *
     * @param array<string, mixed> $place
     *
     * @return array{ref: string, name: string, kind: string, lat: float, lng: float, cc: string, story: string, qid: ?string, osmRef: ?string, year: ?string, web: ?string, links: ?list<mixed>, photo: ?array<string, mixed>}
     */
    private static function row(array $place): array
    {
        $ref = \is_string($place['ref'] ?? null) ? $place['ref'] : '';
        if (!str_starts_with($ref, 'manual:history-')) {
            throw new \InvalidArgumentException(sprintf('A place needs a manual:history-<slug> ref, got "%s".', $ref));
        }
        $lat = $place['lat'] ?? null;
        $lng = $place['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            throw new \InvalidArgumentException(sprintf('%s has no point.', $ref));
        }
        $kind = self::text($place, 'kind', $ref);
        if (null === PlaceKind::label(ItemType::HistoryCulture->letter(), $kind)) {
            throw new \InvalidArgumentException(sprintf('%s: "%s" is not a History & culture kind.', $ref, $kind));
        }
        $osmRef = \is_string($place['osmRef'] ?? null) ? $place['osmRef'] : null;
        if (null !== $osmRef && 1 !== preg_match('#^(node|way)/\d+$#', $osmRef)) {
            throw new \InvalidArgumentException(sprintf('%s: osmRef "%s" is not node/<id> or way/<id>.', $ref, $osmRef));
        }
        $year = $place['year'] ?? null;
        if (null !== $year && (!\is_string($year) || 1 !== preg_match(CatalogField::YEAR_PATTERN, $year))) {
            throw new \InvalidArgumentException(sprintf('%s: year must be three or four digits.', $ref));
        }
        $web = $place['web'] ?? null;
        if (null !== $web && (!\is_string($web) || !str_starts_with($web, 'https://'))) {
            throw new \InvalidArgumentException(sprintf('%s: web must be an https url.', $ref));
        }
        $photo = null;
        if (null !== ($place['photo'] ?? null)) {
            $p = $place['photo'];
            if (!\is_array($p) || !\is_string($p['file'] ?? null) || !\is_string($p['credit'] ?? null) || !\is_string($p['license'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('%s: photo needs a file, a credit and a licence.', $ref));
            }
            $photo = CommonsFile::hotlinkEntry($p['file'], $p['credit'], \is_string($p['user'] ?? null) ? $p['user'] : null, $p['license']);
        }
        $links = $place['links'] ?? null;
        if (null !== $links) {
            if (!\is_array($links) || !array_is_list($links)) {
                throw new \InvalidArgumentException(sprintf('%s: links must be a list.', $ref));
            }
            OutboundLinks::assertValid($links);
        }

        return [
            'ref' => $ref,
            'name' => self::text($place, 'name', $ref),
            'kind' => $kind,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'cc' => strtoupper(self::text($place, 'cc', $ref)),
            'story' => self::text($place, 'story', $ref),
            'qid' => \is_string($place['qid'] ?? null) ? $place['qid'] : null,
            'osmRef' => $osmRef,
            'year' => $year,
            'web' => $web,
            'links' => $links,
            'photo' => $photo,
        ];
    }

    /** @param array<string, mixed> $place */
    private static function text(array $place, string $key, string $ref): string
    {
        $value = $place[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('%s has no %s.', $ref, $key));
        }

        return $value;
    }

    /** Whether the point lies in an operational region. A regionless place is never written. */
    private function inOperationalRegion(float $lat, float $lng): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM region r
              WHERE ST_Contains(r.geom, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326))
                AND '.OperationalRegions::predicate('r').'
              LIMIT 1',
            ['lat' => $lat, 'lng' => $lng],
        );
    }

    /**
     * Why another served row already is this place, or null. One place, one row
     * (catalog-data-model.md §5a): the same name nearby, or a row that is the
     * same Wikidata item or the same OSM object under another ref.
     *
     * @param array{ref: string, name: string, kind: string, lat: float, lng: float, cc: string, story: string, qid: ?string, osmRef: ?string, year: ?string, web: ?string, links: ?list<mixed>, photo: ?array<string, mixed>} $row
     */
    private function holder(array $row): ?string
    {
        $self = ItemSource::Manual->value.':'.$row['ref'];
        $byName = $this->duplicates->existing(ItemType::HistoryCulture->letter(), $row['name'], $row['lat'], $row['lng'], $self);
        if (null !== $byName) {
            return DuplicateGuard::explain($row['name'], ItemSource::Manual, $byName);
        }

        $refs = array_values(array_filter([
            null !== $row['qid'] ? 'wikidata:'.$row['qid'] : null,
            $row['osmRef'],
        ]));
        if ([] === $refs) {
            return null;
        }
        /** @var array{id: int, name: string, source: string}|false $other */
        $other = $this->db->fetchAssociative(
            'SELECT id, name, source FROM item
              WHERE (source_ref IN (:refs) OR osm_ref IN (:refs))
                AND state IN '.ItemState::servedSqlTuple().'
                AND NOT (source = :source AND source_ref = :ref)
              ORDER BY id LIMIT 1',
            ['refs' => $refs, 'source' => ItemSource::Manual->value, 'ref' => $row['ref']],
            ['refs' => ArrayParameterType::STRING],
        );

        return false === $other ? null : sprintf('%s: row #%d "%s" (%s) is the same place', $row['name'], $other['id'], $other['name'], $other['source']);
    }

    /**
     * The photo entry to write: the row's own stored copy of the same Commons
     * file when `app:media:localise-commons` has made one, else the Commons
     * entry. A re-seed must not put the Wikimedia link back over our copy.
     *
     * @param array<string, mixed> $commons
     *
     * @return array<string, mixed>
     */
    private function ownCopyOf(string $ref, array $commons): array
    {
        $stored = $this->db->fetchOne(
            "SELECT attributes->'photo' FROM item WHERE source = 'manual' AND source_ref = :ref AND letter = 'Q'",
            ['ref' => $ref],
        );
        if (!\is_string($stored)) {
            return $commons;
        }
        $own = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);
        $isOurs = \is_array($own) && \is_string($own['sm'] ?? null) && !str_contains($own['sm'], 'wikimedia.org');

        // One file, two spellings: the seed writes the page plain, the stored copy URL-encoded.
        $sameFile = \is_string($own['source'] ?? null) && \is_string($commons['source'] ?? null)
            && rawurldecode($own['source']) === rawurldecode($commons['source']);

        return $isOurs && $sameFile ? $own : $commons;
    }

    /** The OSM object this row is, unless a person has edited the row since (their osm_ref stands). */
    private function claimOsmRef(string $ref, ?string $osmRef): void
    {
        if (null === $osmRef) {
            return;
        }
        $this->db->executeStatement(
            "UPDATE item SET osm_ref = :osm, osm_checked_at = NOW()
              WHERE source = 'manual' AND source_ref = :ref AND letter = 'Q'
                AND osm_ref IS DISTINCT FROM :osm
                AND NOT EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id)",
            ['osm' => $osmRef, 'ref' => $ref],
        );
    }

    /**
     * docs/specs/catalog-data-model.md §6: smallest-area-wins; only the rows this run wrote.
     *
     * @param list<string> $refs
     */
    private function recomputeMembership(array $refs): void
    {
        if ([] === $refs) {
            return;
        }
        $this->db->executeStatement(
            "UPDATE item SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (i.id) i.id AS item_id, r.id AS region_id
                FROM item i JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(i.geom))
                WHERE i.source = 'manual' AND i.source_ref IN (:refs)
                ORDER BY i.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE item.id = m.item_id",
            ['refs' => $refs],
            ['refs' => ArrayParameterType::STRING],
        );
    }
}
