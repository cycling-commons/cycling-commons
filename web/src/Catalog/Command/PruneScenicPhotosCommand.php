<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\ItemType;
use App\Media\Commons\CommonsPhotoState;
use App\Media\Commons\CommonsPhotoUsage;
use App\Media\MediaDecisionService;
use App\Media\MediaStorage;
use App\Media\PhotoFacts;
use App\Media\PhotoOrigin;
use App\Media\PhotoPlace;
use App\Media\PhotoReason;
use App\Media\PhotoValidator;
use App\Media\PhotoVerdict;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remove every stored photo a scenic view may not show.
 *
 * PhotoValidator does not show a photo on a scenic view (letter P) unless its
 * camera stood within reach of the pin. The owner wants those photos gone, not
 * only hidden (2026-09-14). With `--write`:
 *
 * - every Commons or imported photo entry on a P item (any state) that
 *   PhotoValidator does not show there is removed from the item's `photo` /
 *   `photos`, and the item's `updated_at` moves so the catalog payload version
 *   moves with it;
 * - a cached Commons file's stored objects are deleted when nothing may still
 *   show it: no item entry names it, no coverage point of another letter names
 *   it, and no P coverage point names it with PhotoValidator showing it there.
 *   The candidates are the files removed from P items and the files P coverage
 *   points name that PhotoValidator does not show there. The `commons_photo`
 *   row stays, with its credit, licence and camera and no storage: `declined`
 *   when the refusal is about the place, `unusable` when it is about the file.
 *   The next drawer open or harvest therefore knows the refusal without a
 *   download or a second call to Commons, and a place that may show the file
 *   still gets it (CommonsPhotoAdmission::admit()).
 *
 * Rider photos (entries with an upload `id`, and `media_upload` rows on P
 * items) are never removed here. A curator can confirm on the map that a rider
 * photo was taken at the pin; the ones with neither a usable GPS distance nor
 * that confirmation are listed for a person to decide.
 *
 * Where the camera stood is read from the database when it is known there:
 * `commons_photo.camera_*` for a file that has been checked, and
 * `media_upload.gps_distance_m`, `location_confirmed_at` and the pins they
 * were recorded at for a rider upload. An entry's own `cameraAt`, `distanceM`
 * or `locationConfirmed` is used only when the database has no answer.
 *
 * Without `--write` it reports what it would remove and changes nothing.
 *
 * @see docs/specs/scenic-views.md §8
 *
 * @api
 */
#[AsCommand(name: 'app:scenic:prune-photos', description: 'Remove stored photos a scenic view may not show')]
final class PruneScenicPhotosCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly MediaStorage $storage,
        private readonly CommonsPhotoUsage $usage,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Remove for real. Without it, nothing changes.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('write');
        $letter = ItemType::ScenicViews->letter();

        [$cached, $stored] = $this->commonsPhotos();
        $uploads = $this->riderUploads($letter);

        // Step 1: the entries each P item loses.
        /** @var array<int, array{name: string, raw: string, photo: bool, photos: list<mixed>|null, removed: list<string>}> $changes */
        $changes = [];
        /** @var array<string, string> $riders upload id => report line */
        $riders = [];
        $entriesRemoved = 0;
        /** @var array<string, PhotoReason> $removedFiles file => why the view dropped it */
        $removedFiles = [];

        /** @var list<array{id: int|string, name: string, lat: float|string|null, lng: float|string|null, raw: string}> $items */
        $items = $this->db->fetchAllAssociative(
            "SELECT id, name, ST_Y(geom) AS lat, ST_X(geom) AS lng, attributes::text AS raw FROM item
              WHERE letter = :l AND (attributes->'photo' IS NOT NULL OR attributes->'photos' IS NOT NULL)
              ORDER BY id",
            ['l' => $letter],
        );
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $place = PhotoPlace::of($letter, $item['lat'], $item['lng']);
            $attributes = json_decode($item['raw'], true);
            if (!\is_array($attributes)) {
                continue;
            }

            $removed = [];
            $shows = function (mixed $entry) use ($id, $item, $place, $cached, $uploads, &$riders, &$removed, &$removedFiles): bool {
                $upload = \is_array($entry) && \is_string($entry['id'] ?? null) ? $entry['id'] : null;
                if (null !== $upload) {
                    $facts = \array_key_exists($upload, $uploads) ? self::riderFacts($upload, $uploads[$upload]) : PhotoFacts::fromEntry($entry);
                    if (!PhotoValidator::verdict($facts, $place)->shows()) {
                        $riders[$upload] = self::riderLine($upload, PhotoValidator::reachM($facts, $place), $item['name'], $id);
                    }

                    return true;
                }

                $file = CommonsPhotoUsage::fileOf($entry);
                $facts = PhotoFacts::fromEntry($entry);
                if (null !== $file && ($cached[$file]['checked'] ?? false)) {
                    // The camera recorded on the file decides over the entry's own.
                    $facts = new PhotoFacts($facts->origin, $facts->licence, $facts->author, cameraAt: $cached[$file]['camera'] ?? null);
                }
                $verdict = PhotoValidator::verdict($facts, $place);
                if ($verdict->shows()) {
                    return true;
                }
                $removed[] = $file ?? (\is_array($entry) && \is_string($entry['source'] ?? null) ? $entry['source'] : 'a photo with no Commons file');
                if (null !== $file && null !== $verdict->reason) {
                    $removedFiles[$file] ??= $verdict->reason;
                }

                return false;
            };

            $dropPhoto = \array_key_exists('photo', $attributes) && !$shows($attributes['photo']);
            $photos = null;
            if (\array_key_exists('photos', $attributes)) {
                $gallery = \is_array($attributes['photos']) ? array_values($attributes['photos']) : [];
                $kept = array_values(array_filter($gallery, $shows));
                if (\count($kept) !== \count($gallery) || !\is_array($attributes['photos'])) {
                    $photos = $kept;
                }
            }
            if (!$dropPhoto && null === $photos) {
                continue;
            }

            $entriesRemoved += \count($removed);
            $changes[$id] = ['name' => $item['name'], 'raw' => $item['raw'], 'photo' => $dropPhoto, 'photos' => $photos, 'removed' => $removed];
        }

        // Rider uploads on P items that no entry shows yet.
        foreach ($uploads as $upload => $known) {
            $place = new PhotoPlace($letter, $known['lat'], $known['lng']);
            if (!isset($riders[$upload]) && !PhotoValidator::verdict(self::riderFacts($upload, $known), $place)->shows()) {
                $riders[$upload] = self::riderLine($upload, PhotoValidator::reachM(self::riderFacts($upload, $known), $place), null, null);
            }
        }

        // Step 2: which cached Commons files nothing may still show.
        $points = $this->usage->coveragePoints(array_keys($stored));
        $candidates = array_intersect_key($removedFiles, $stored);
        foreach ($points as $file => $refs) {
            foreach ($refs as $ref) {
                if ($letter !== $ref['letter']) {
                    continue;
                }
                $verdict = self::cachedVerdict($cached, $file, new PhotoPlace($letter, $ref['lat'], $ref['lng']));
                if (!$verdict->shows() && null !== $verdict->reason) {
                    $candidates[$file] ??= $verdict->reason;
                }
            }
        }
        $candidateFiles = array_map('strval', array_keys($candidates));
        sort($candidateFiles);

        $stillNamed = $this->usage->namedByItems($candidateFiles, array_keys($changes));
        foreach ($changes as $change) {
            foreach (CommonsPhotoUsage::filesIn(self::afterChange($change['raw'], $change['photo'], $change['photos'])) as $file) {
                $stillNamed[$file] = true;
            }
        }

        $delete = [];
        $kept = [];
        foreach ($candidateFiles as $file) {
            $reason = null;
            if (isset($stillNamed[$file])) {
                $reason = 'an item still shows it';
            } else {
                foreach ($points[$file] ?? [] as $ref) {
                    if ($letter !== $ref['letter']) {
                        $reason = \sprintf('a %s coverage point names it', $ref['letter']);
                        break;
                    }
                    if (self::cachedVerdict($cached, $file, new PhotoPlace($letter, $ref['lat'], $ref['lng']))->shows()) {
                        $reason = 'a scenic coverage point near its camera names it';
                        break;
                    }
                }
            }
            if (null === $reason) {
                $delete[$file] = $candidates[$file];
            } else {
                $kept[$file] = $reason;
            }
        }
        $neverChecked = \count(array_filter(array_keys($delete), static fn (string $f): bool => !$stored[$f]['checked']));

        $io->section(\sprintf('%s photos scenic views may not show', $write ? 'Removing' : 'Would remove'));
        $io->definitionList(
            ['Scenic items with photos' => (string) \count($items)],
            ['Photo entries removed' => (string) $entriesRemoved],
            ['Items changed' => (string) \count($changes)],
            ['Commons files whose stored copy is deleted' => \sprintf('%d (%d never checked for a camera)', \count($delete), $neverChecked)],
            ['Commons files kept, still shown elsewhere' => (string) \count($kept)],
            ['Rider photos with no usable GPS distance and no curator confirmation, kept for a person' => (string) \count($riders)],
        );
        if ($output->isVerbose()) {
            if ([] !== $changes) {
                $io->text('Items changed:');
                $io->listing(array_map(
                    static fn (int $id, array $c): string => \sprintf('%s (#%d): %s', $c['name'], $id, implode(', ', $c['removed'])),
                    array_keys($changes), $changes,
                ));
            }
            if ([] !== $delete) {
                $io->text('Commons files whose stored copy is deleted:');
                $io->listing(array_map(static fn (string $f, PhotoReason $why): string => \sprintf('%s: %s', $f, $why->value), array_keys($delete), $delete));
            }
            if ([] !== $kept) {
                $io->text('Commons files kept:');
                $io->listing(array_map(static fn (string $f, string $why): string => \sprintf('%s: %s', $f, $why), array_keys($kept), $kept));
            }
        }
        if ([] !== $riders) {
            $io->text('Rider photos with no usable GPS distance and no curator confirmation, kept for a person:');
            $io->listing(array_values($riders));
        }

        if (!$write) {
            $io->note('Dry run: nothing changed. Re-run with --write to remove them.');

            return Command::SUCCESS;
        }

        $objects = $this->db->transactional(fn (Connection $db): array => $this->apply($db, $changes, $delete, $stored));

        // Objects last: a failed database step must never leave rows pointing at deleted files.
        foreach ($objects as $o) {
            $this->storage->deletePrefix($o['bucket'], $o['prefix']);
        }

        $io->success(\sprintf('Removed %d photo entr%s from %d item(s) and deleted the stored copy of %d Commons file(s).',
            $entriesRemoved, 1 === $entriesRemoved ? 'y' : 'ies', \count($changes), \count($delete)));

        return Command::SUCCESS;
    }

    /**
     * The database half of `--write`, inside one transaction.
     *
     * @param array<int, array{photo: bool, photos: list<mixed>|null, raw: string, name: string, removed: list<string>}> $changes
     * @param array<string, PhotoReason>                                                                                 $delete  file => why
     * @param array<string, array{bucket: ?string, prefix: ?string, checked: bool}>                                      $stored
     *
     * @return list<array{bucket: string, prefix: string}> the stored objects of the settled rows
     */
    private function apply(Connection $db, array $changes, array $delete, array $stored): array
    {
        foreach ($changes as $id => $change) {
            $attributes = 'attributes';
            $params = ['id' => $id, 'raw' => $change['raw']];
            if ($change['photo']) {
                $attributes = "({$attributes} - 'photo')";
            }
            if ([] === $change['photos']) {
                $attributes = "({$attributes} - 'photos')";
            } elseif (null !== $change['photos']) {
                $attributes = "jsonb_set({$attributes}, '{photos}', CAST(:photos AS jsonb))";
                $params['photos'] = json_encode($change['photos'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
            }
            // The attributes must still be the ones read above; an edit in
            // between would otherwise be overwritten.
            $updated = $db->executeStatement(
                "UPDATE item SET attributes = {$attributes}, updated_at = NOW() WHERE id = :id AND attributes = CAST(:raw AS jsonb)",
                $params,
            );
            if (1 !== (int) $updated) {
                throw new \RuntimeException(\sprintf('Item #%d changed while this ran. Nothing was written; run the command again.', $id));
            }
        }

        $objects = [];
        $byState = [];
        foreach ($delete as $file => $why) {
            $bucket = $stored[$file]['bucket'] ?? null;
            $prefix = $stored[$file]['prefix'] ?? null;
            if (null !== $bucket && null !== $prefix) {
                $objects[] = ['bucket' => $bucket, 'prefix' => $prefix];
            }
            $state = $why->concernsPlace() ? CommonsPhotoState::Declined : CommonsPhotoState::Unusable;
            $byState[$state->value][$why->value][] = (string) $file;
        }
        // The row stays, without storage: the refusal is known next time with no download.
        foreach ($byState as $state => $reasons) {
            foreach ($reasons as $why => $files) {
                foreach (array_chunk($files, 500) as $chunk) {
                    $db->executeStatement(
                        'UPDATE commons_photo
                            SET state = :s, failed_reason = :r, storage_bucket = NULL, storage_prefix = NULL,
                                width = NULL, height = NULL, ready_at = NULL
                          WHERE file IN (:f)',
                        ['s' => $state, 'r' => $why, 'f' => $chunk],
                        ['f' => ArrayParameterType::STRING],
                    );
                }
            }
        }

        return $objects;
    }

    /**
     * What each cached file's row says (credit, licence, camera), and where it
     * is stored.
     *
     * Only rows that hold a stored copy are candidates for deletion; the
     * facts are read for every row. A file whose camera was never checked has
     * `checked` false, so an item entry's own `cameraAt` is used for it.
     *
     * @return array{0: array<string, array{credit: ?string, license: ?string, camera: array{0: float, 1: float}|null, checked: bool}>, 1: array<string, array{bucket: ?string, prefix: ?string, checked: bool}>}
     */
    private function commonsPhotos(): array
    {
        $cached = [];
        $stored = [];
        /** @var array{file: string, credit: ?string, license: ?string, camera_lat: float|string|null, camera_lng: float|string|null, checked: bool, storage_bucket: ?string, storage_prefix: ?string} $row */
        foreach ($this->db->iterateAssociative(
            'SELECT file, credit, license, camera_lat, camera_lng, camera_checked_at IS NOT NULL AS checked, storage_bucket, storage_prefix FROM commons_photo',
        ) as $row) {
            $file = (string) $row['file'];
            $checked = (bool) $row['checked'];
            $cached[$file] = [
                'credit' => $row['credit'],
                'license' => $row['license'],
                'camera' => $checked && null !== $row['camera_lat'] && null !== $row['camera_lng']
                    ? [(float) $row['camera_lat'], (float) $row['camera_lng']]
                    : null,
                'checked' => $checked,
            ];
            $bucket = \is_string($row['storage_bucket']) && '' !== $row['storage_bucket'] ? $row['storage_bucket'] : null;
            $prefix = \is_string($row['storage_prefix']) && '' !== $row['storage_prefix'] ? $row['storage_prefix'] : null;
            if (null !== $bucket && null !== $prefix) {
                $stored[$file] = ['bucket' => $bucket, 'prefix' => $prefix, 'checked' => $checked];
            }
        }

        return [$cached, $stored];
    }

    /**
     * PhotoValidator's verdict on a cached file for one place, from its row.
     *
     * @param array<string, array{credit: ?string, license: ?string, camera: array{0: float, 1: float}|null, checked: bool}> $cached
     */
    private static function cachedVerdict(array $cached, string $file, PhotoPlace $place): PhotoVerdict
    {
        $row = $cached[$file] ?? ['credit' => null, 'license' => null, 'camera' => null];

        return PhotoValidator::verdict(PhotoFacts::commons($row['license'], $row['credit'], false, false, $row['camera']), $place);
    }

    /**
     * A rider upload's facts from what the database knows about it.
     *
     * @param array{distanceM: int|null, locationConfirmed: bool, distancePin: array{0: float, 1: float}|null, confirmedPin: array{0: float, 1: float}|null, lat: float|null, lng: float|null} $known
     */
    private static function riderFacts(string $upload, array $known): PhotoFacts
    {
        return new PhotoFacts(
            PhotoOrigin::Rider,
            MediaDecisionService::LICENSE,
            null,
            distanceM: $known['distanceM'],
            locationConfirmed: $known['locationConfirmed'],
            uploadId: $upload,
            distancePin: $known['distancePin'],
            confirmedPin: $known['confirmedPin'],
        );
    }

    /**
     * What the database knows about each rider upload on items of this letter.
     *
     * @return array<string, array{distanceM: int|null, locationConfirmed: bool, distancePin: array{0: float, 1: float}|null, confirmedPin: array{0: float, 1: float}|null, lat: float|null, lng: float|null}> upload id => gps_distance_m, whether a curator confirmed the location, the pins those were recorded at, and the item's pin
     */
    private function riderUploads(string $letter): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT mu.id::text AS id, mu.gps_distance_m, mu.location_confirmed_at IS NOT NULL AS confirmed,
                    mu.gps_distance_pin_lat, mu.gps_distance_pin_lng, mu.location_confirmed_pin_lat, mu.location_confirmed_pin_lng,
                    ST_Y(ST_PointOnSurface(i.geom)) AS lat, ST_X(ST_PointOnSurface(i.geom)) AS lng
               FROM media_upload mu JOIN item i ON i.id = mu.item_id WHERE i.letter = :l',
            ['l' => $letter],
        ) as $row) {
            $distance = $row['gps_distance_m'];
            $out[(string) $row['id']] = [
                'distanceM' => is_numeric($distance) ? (int) $distance : null,
                'locationConfirmed' => (bool) $row['confirmed'],
                'distancePin' => PhotoFacts::camera(self::floats($row['gps_distance_pin_lat'], $row['gps_distance_pin_lng'])),
                'confirmedPin' => PhotoFacts::camera(self::floats($row['location_confirmed_pin_lat'], $row['location_confirmed_pin_lng'])),
                'lat' => is_numeric($row['lat']) ? (float) $row['lat'] : null,
                'lng' => is_numeric($row['lng']) ? (float) $row['lng'] : null,
            ];
        }

        return $out;
    }

    /**
     * An item's photo attributes as the write leaves them.
     *
     * @param list<mixed>|null $photos
     *
     * @return array<array-key, mixed>
     */
    private static function afterChange(string $raw, bool $dropPhoto, ?array $photos): array
    {
        $attributes = json_decode($raw, true);
        if (!\is_array($attributes)) {
            return [];
        }
        if ($dropPhoto) {
            unset($attributes['photo']);
        }
        if (null !== $photos) {
            $attributes['photos'] = $photos;
        }

        return $attributes;
    }

    /** `$distance` is PhotoValidator::reachM(): the farthest the camera may have stood from the pin. */
    private static function riderLine(string $upload, ?int $distance, ?string $item, ?int $id): string
    {
        $where = null === $item ? 'on a scenic item' : \sprintf('on %s (#%d)', $item, (int) $id);

        return \sprintf('%s %s: %s', $upload, $where, null !== $distance ? 'up to '.$distance.' m from the pin' : 'no GPS distance');
    }

    /**
     * Two database values as a `[lat, lng]` pair of floats, for PhotoFacts::camera().
     *
     * @return list<float>|null
     */
    private static function floats(mixed $lat, mixed $lng): ?array
    {
        return is_numeric($lat) && is_numeric($lng) ? [(float) $lat, (float) $lng] : null;
    }
}
