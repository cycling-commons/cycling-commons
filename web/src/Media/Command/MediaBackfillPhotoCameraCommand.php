<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use App\Catalog\ScenicPhotoRule;
use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsFile;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsUnavailable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Record where the camera stood for photos stored before anybody asked.
 *
 * A scenic view shows a photo only when its camera stood near the pin
 * (ScenicPhotoRule), and an unknown camera is a refusal. Every Commons photo
 * fetched before `commons_photo.camera_*` existed is therefore hidden on the
 * scenic layer until its camera is known, including the ones taken right at
 * the pin. This command asks.
 *
 * Two passes:
 *
 * 1. **Commons.** Every ready `commons_photo` row whose camera was never asked
 *    for (all ready rows with --recheck) is asked in batches of
 *    CommonsApi::CAMERA_BATCH_MAX titles, one POST per batch with the
 *    identifying User-Agent, a pause between batches. The answer is recorded
 *    with its check time either way, so "no camera" is remembered. A batch
 *    Commons fails to answer is left unchecked for the next run.
 * 2. **Item attributes.** Stored photo entries do not read `commons_photo` at
 *    serve time, so each entry whose `source` (or `sm`) names a checked file
 *    gets that file's `cameraAt`, or loses a stale one. A rider's entry (one
 *    with an upload `id`) gets `distanceM` from `media_upload.gps_distance_m`,
 *    which approvals before the rule did not copy.
 *
 * Dry run by default: Commons is still asked, because asking is only reading,
 * and the report says what --write would record and how many scenic photos
 * would then be shown.
 *
 * @see docs/specs/photo-uploads.md §5f
 * @see docs/specs/scenic-views.md §8
 *
 * @api
 */
#[AsCommand(
    name: 'app:media:backfill-photo-camera',
    description: 'Record where the camera stood for stored Commons and rider photos (scenic photo rule)',
)]
final class MediaBackfillPhotoCameraCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly CommonsPhotoRepository $photos,
        private readonly CommonsApi $api,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Record the answers and stamp item photos. Without it, nothing is written.')
            ->addOption('recheck', null, InputOption::VALUE_NONE, 'Ask again for every ready file, not only the ones never asked.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ask about at most this many files.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Milliseconds to wait after each Commons request (default 1000). 0 disables the pause.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('write');
        $limitRaw = $input->getOption('limit');
        $limit = \is_string($limitRaw) && ctype_digit($limitRaw) && (int) $limitRaw > 0 ? (int) $limitRaw : null;
        $sleepRaw = $input->getOption('sleep');
        // Wikimedia asks clients to come one at a time and unhurried, and a
        // backfill has no deadline (the same pause LocaliseCommonsPhotosCommand keeps).
        $pause = \is_string($sleepRaw) && ctype_digit($sleepRaw) ? min((int) $sleepRaw, 60_000) : 1000;

        if (!$write) {
            $io->note('Dry run: Commons is asked, nothing is written. Add --write to record.');
        }

        // Pass 1: ask Commons.
        $files = $this->photos->filesForCameraCheck((bool) $input->getOption('recheck'), $limit);
        $io->section(sprintf('%d file%s to ask Commons about', \count($files), 1 === \count($files) ? '' : 's'));

        /** @var array<string, array{0: float, 1: float}|null> $answers */
        $answers = [];
        $failed = 0;
        foreach (array_chunk($files, CommonsApi::CAMERA_BATCH_MAX) as $i => $batch) {
            if ($i > 0 && $pause > 0) {
                usleep($pause * 1000);
            }
            try {
                $cameras = $this->api->cameraLocations($batch);
            } catch (CommonsUnavailable $e) {
                $failed += \count($batch);
                $io->writeln(sprintf('  <error>x</error> batch %d: Commons did not answer (%s), left for the next run', $i + 1, $e->getMessage()));
                continue;
            }
            foreach ($cameras as $file => $camera) {
                $answers[$file] = $camera;
                if ($write) {
                    $this->photos->recordCamera($file, $camera[0] ?? null, $camera[1] ?? null);
                }
            }
        }
        $withCamera = \count(array_filter($answers, static fn (?array $c): bool => null !== $c));

        // Everything known: earlier answers from the table, this run's on top
        // (in a dry run they exist only here).
        $known = $this->storedCameras();
        foreach ($answers as $file => $camera) {
            $known[$file] = $camera;
        }

        // Pass 2: stamp item photo entries.
        [$itemsChanged, $entriesChanged, $scenicShown, $scenicHidden] = $this->stampItems($known, $write);

        [$poiNear, $poiFar] = $this->scenicPoiReach($known);

        $io->newLine();
        $io->definitionList(
            ['files asked' => (string) \count($answers)],
            ['with a camera point' => (string) $withCamera],
            ['without one' => (string) (\count($answers) - $withCamera)],
            ['not answered' => (string) $failed],
            [($write ? 'item photo entries stamped' : 'item photo entries --write would stamp') => sprintf('%d on %d item%s', $entriesChanged, $itemsChanged, 1 === $itemsChanged ? '' : 's')],
            ['scenic item photos shown / hidden' => sprintf('%d / %d', $scenicShown, $scenicHidden)],
            ['scenic POIs whose cached photo has a camera within '.ScenicPhotoRule::MAX_CAMERA_DISTANCE_M.' m / not' => sprintf('%d / %d', $poiNear, $poiFar)],
        );

        if ($write) {
            $io->success('Recorded.');
        }

        return Command::SUCCESS;
    }

    /**
     * Every answer already in the table.
     *
     * @return array<string, array{0: float, 1: float}|null>
     */
    private function storedCameras(): array
    {
        /** @var list<array{file: string, camera_lat: float|string|null, camera_lng: float|string|null}> $rows */
        $rows = $this->db->fetchAllAssociative('SELECT file, camera_lat, camera_lng FROM commons_photo WHERE camera_checked_at IS NOT NULL');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['file']] = is_numeric($r['camera_lat']) && is_numeric($r['camera_lng'])
                ? [(float) $r['camera_lat'], (float) $r['camera_lng']]
                : null;
        }

        return $out;
    }

    /**
     * Stamp `cameraAt` and `distanceM` into stored photo entries.
     *
     * @param array<string, array{0: float, 1: float}|null> $known file => camera, for files Commons has answered about
     *
     * @return array{0: int, 1: int, 2: int, 3: int} items changed, entries changed, scenic photos shown, scenic photos hidden
     */
    private function stampItems(array $known, bool $write): array
    {
        /** @var list<array{id: int|string, letter: string, lat: float|string|null, lng: float|string|null, photo: string|null, photos: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, letter, ST_Y(ST_PointOnSurface(geom)) AS lat, ST_X(ST_PointOnSurface(geom)) AS lng,
                    attributes->'photo' AS photo, attributes->'photos' AS photos
               FROM item
              WHERE jsonb_typeof(attributes->'photo') = 'object' OR jsonb_typeof(attributes->'photos') = 'array'
              ORDER BY id",
        );

        $decoded = [];
        $uploadIds = [];
        foreach ($rows as $row) {
            $single = \is_string($row['photo']) ? json_decode($row['photo'], true) : null;
            $gallery = \is_string($row['photos']) ? json_decode($row['photos'], true) : null;
            $single = \is_array($single) ? $single : null;
            $gallery = \is_array($gallery) && array_is_list($gallery) ? $gallery : null;
            foreach ([$single, ...($gallery ?? [])] as $entry) {
                if (\is_array($entry) && \is_string($entry['id'] ?? null) && Uuid::isValid($entry['id'])) {
                    $uploadIds[$entry['id']] = true;
                }
            }
            $decoded[] = [$row, $single, $gallery];
        }
        $distances = $this->riderDistances(array_keys($uploadIds));

        $itemsChanged = 0;
        $entriesChanged = 0;
        $shown = 0;
        $hidden = 0;
        foreach ($decoded as [$row, $single, $gallery]) {
            $changed = 0;
            $newSingle = null === $single ? null : $this->stamp($single, $known, $distances, $changed);
            $newGallery = null;
            if (null !== $gallery) {
                $newGallery = [];
                foreach ($gallery as $e) {
                    $newGallery[] = \is_array($e) ? $this->stamp($e, $known, $distances, $changed) : $e;
                }
            }

            if (ScenicPhotoRule::appliesTo($row['letter'])) {
                $lat = is_numeric($row['lat']) ? (float) $row['lat'] : null;
                $lng = is_numeric($row['lng']) ? (float) $row['lng'] : null;
                foreach ([$newSingle, ...($newGallery ?? [])] as $entry) {
                    if (!\is_array($entry)) {
                        continue;
                    }
                    ScenicPhotoRule::allows($entry, $lat, $lng) ? ++$shown : ++$hidden;
                }
            }

            if (0 === $changed) {
                continue;
            }
            ++$itemsChanged;
            $entriesChanged += $changed;
            if ($write) {
                $this->writeItem((int) $row['id'], null === $single ? null : $newSingle, $newGallery);
            }
        }

        return [$itemsChanged, $entriesChanged, $shown, $hidden];
    }

    /**
     * One entry with its camera facts brought up to date.
     *
     * @param array<array-key, mixed>                       $entry
     * @param array<string, array{0: float, 1: float}|null> $known
     * @param array<string, int|null>                       $distances upload id => gps_distance_m
     *
     * @return array<array-key, mixed>
     */
    private function stamp(array $entry, array $known, array $distances, int &$changed): array
    {
        $before = $entry;

        $id = $entry['id'] ?? null;
        if (\is_string($id) && \array_key_exists($id, $distances) && !\array_key_exists('distanceM', $entry)) {
            $entry['distanceM'] = $distances[$id];
        }

        $file = null;
        foreach (['source', 'sm'] as $key) {
            if (\is_string($entry[$key] ?? null)) {
                $file = CommonsFile::fromTags(['image' => $entry[$key]]);
                if (null !== $file) {
                    break;
                }
            }
        }
        if (null !== $file && \array_key_exists($file, $known)) {
            if (null === $known[$file]) {
                unset($entry['cameraAt']);
            } else {
                $entry['cameraAt'] = $known[$file];
            }
        }

        if ($entry !== $before) {
            ++$changed;
        }

        return $entry;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, int|null>
     */
    private function riderDistances(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: string, gps_distance_m: int|string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id::text AS id, gps_distance_m FROM media_upload WHERE id::text IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['id']] = null === $r['gps_distance_m'] ? null : (int) $r['gps_distance_m'];
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed>|null $photo
     * @param list<mixed>|null             $photos
     */
    private function writeItem(int $id, ?array $photo, ?array $photos): void
    {
        $attributes = 'attributes';
        $params = ['id' => $id];
        if (null !== $photo) {
            $attributes = "jsonb_set({$attributes}, '{photo}', CAST(:photo AS jsonb))";
            $params['photo'] = json_encode($photo, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
        if (null !== $photos) {
            $attributes = "jsonb_set({$attributes}, '{photos}', CAST(:photos AS jsonb))";
            $params['photos'] = json_encode($photos, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
        // updated_at moves so the catalog payload's version tag moves with it,
        // and a cached catalog.json does not keep serving the old entries.
        $this->db->executeStatement('UPDATE item SET attributes = '.$attributes.', updated_at = NOW() WHERE id = :id', $params);
    }

    /**
     * How many scenic coverage POIs have a cached photo whose camera stood
     * within reach, and how many have one that did not (or has no camera).
     *
     * A report figure only. The file is found the two ways the photo endpoint
     * finds it, a `wikimedia_commons=File:` tag or the Wikidata P18 already
     * cached in `wikidata_image`. An `image` tag holding a Commons URL is not
     * counted, which undercounts slightly.
     *
     * @param array<string, array{0: float, 1: float}|null> $known
     *
     * @return array{0: int, 1: int}
     */
    private function scenicPoiReach(array $known): array
    {
        if (null === $this->db->fetchOne("SELECT to_regclass('coverage_poi')")) {
            return [0, 0];
        }
        /** @var list<array{file: string, lat: float|string, lng: float|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT f.file, ST_Y(cp.geom) AS lat, ST_X(cp.geom) AS lng
               FROM coverage_poi cp
               JOIN LATERAL (
                    SELECT COALESCE(
                        CASE WHEN cp.tags->>'wikimedia_commons' LIKE 'File:%'
                             THEN replace(substr(cp.tags->>'wikimedia_commons', 6), '_', ' ') END,
                        (SELECT wi.file FROM wikidata_image wi WHERE wi.qid = cp.tags->>'wikidata')
                    ) AS file
               ) f ON f.file IS NOT NULL
               JOIN commons_photo c ON c.file = f.file AND c.state = 'ready'
              WHERE cp.letter = :letter",
            ['letter' => 'P'],
        );

        $near = 0;
        $far = 0;
        foreach ($rows as $r) {
            $camera = $known[$r['file']] ?? null;
            ScenicPhotoRule::allows(['cameraAt' => $camera], (float) $r['lat'], (float) $r['lng']) ? ++$near : ++$far;
        }

        return [$near, $far];
    }
}
