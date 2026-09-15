<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use App\Catalog\ItemType;
use App\Media\Commons\CommonsFile;
use App\Media\Commons\CommonsPhotoAdmission;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use App\Media\ContinentResolver;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\MessageHandler\FetchCommonsPhotoHandler;
use App\Media\PhotoFacts;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bring the catalogue's and the routes' remaining Commons hotlinks into our own storage.
 *
 * A town card and a coverage POI have no photo until somebody opens them, so
 * the code goes and finds one at request time, and that path downloads the
 * file, re-encodes it and keeps it in our bucket. A seeded or harvested
 * catalogue item, and a harvested recommended route, carry another shape: the
 * seeders, the Wallonia enrich step and the route harvest turned a Commons file
 * into a `Special:FilePath` URL and stored the URL, so the drawer printed it
 * and the rider's browser fetched the pixels from Wikimedia.
 *
 * That costs three things. Wikimedia sees a request per rider per photo. The
 * file can be renamed or deleted there and our page silently loses its
 * picture. And the URL is a redirect chain we do not control: thumbnails moved
 * to `thumb.wikimedia.org` in 2026, outside our own `img-src`, and every one of
 * those photos went blank.
 *
 * This walks those rows (`item` and `recommended_route`) and puts each file
 * through the SAME path a town card uses, handler and all: PhotoValidator
 * first, judged against the place (an item's own letter and pin; a route is
 * letter R at `ST_PointOnSurface(geom)`, PhotoPlace::route()), then download,
 * virus scan, re-encode to three webp sizes, store. Only when PhotoValidator
 * shows the stored copy on that place is the row's `photo` / `photos` entry
 * rewritten to our URLs, carrying the credit, the uploader's Commons page, the
 * licence and the file's Commons page with it. That last part is not
 * decoration: CC BY-SA is satisfied only while the attribution travels with
 * the copy, which is why the shape comes from
 * {@see CommonsPhotoAdmission::readyPhoto()} rather than being written twice.
 *
 * A file PhotoValidator refuses (licence, no_author, non_free, camera_far and
 * so on) is reported with the reason. On an item the entry is left as it was:
 * every display filter hides it, `app:scenic:prune-photos` removes it from a
 * scenic item, and `--recheck-licences` can still reach it. A route keeps no
 * hotlink: the refused entry is taken off the route, so its drawer shows no
 * photo rather than a blocked one. A fetch that failed on our side (Commons
 * down, no bucket) is kept on both for the next run.
 *
 * Safe to run repeatedly. A row already localised is skipped, and a file we
 * already hold (a coverage POI may have fetched it first) is reused rather
 * than downloaded again.
 *
 * @see docs/specs/photo-uploads.md §5f
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
#[AsCommand(
    name: 'app:media:localise-commons',
    description: 'Download the catalogue\'s and the routes\' remaining Commons hotlinks into our own storage',
)]
final class LocaliseCommonsPhotosCommand extends Command
{
    /**
     * A hotlink is any stored photo whose small variant still points at Wikimedia.
     *
     * BOTH shapes, and the gallery is easy to forget: `photo` is the legacy
     * singular field the seeders wrote, `photos` is the array a rider's uploads
     * live in (photo-uploads.md §5) and which `SeedManualCatalogCommand` also
     * used for the hand-curated multi-photo climbs. Reading only the first cost
     * five photos on four items in the dev catalogue, and they would have gone
     * on hotlinking with the report saying everything was done.
     */
    private const string HOTLINK_WHERE = <<<'SQL'
        (t.attributes->'photo'->>'sm' LIKE '%wikimedia.org%'
           OR EXISTS (
                SELECT 1 FROM jsonb_array_elements(
                    CASE WHEN jsonb_typeof(t.attributes->'photos') = 'array'
                         THEN t.attributes->'photos' ELSE '[]'::jsonb END
                ) AS g
                WHERE g->>'sm' LIKE '%wikimedia.org%'
           ))
        SQL;

    /** Catalogue items, judged against their own letter and pin. */
    private const string ITEM_SQL = <<<'SQL'
        SELECT 'item' AS kind, t.id, t.letter, t.name, t.country_code,
               ST_Y(ST_PointOnSurface(t.geom)) AS lat, ST_X(ST_PointOnSurface(t.geom)) AS lng,
               t.attributes->'photo' AS photo, t.attributes->'photos' AS photos
        FROM item t
        WHERE
        SQL;

    /**
     * Recommended routes, judged as letter R at `ST_PointOnSurface(geom)`
     * (PhotoPlace::route()). A route stores no country of its own; its region
     * does, and the pin is the fallback.
     */
    private const string ROUTE_SQL = <<<'SQL'
        SELECT 'route' AS kind, t.id, 'R' AS letter, t.name, g.country_code,
               ST_Y(ST_PointOnSurface(t.geom)) AS lat, ST_X(ST_PointOnSurface(t.geom)) AS lng,
               t.attributes->'photo' AS photo, t.attributes->'photos' AS photos
        FROM recommended_route t
        LEFT JOIN region g ON g.id = t.region_id
        WHERE
        SQL;

    public function __construct(
        private readonly Connection $db,
        private readonly CommonsPhotoRepository $photos,
        private readonly CommonsPhotoAdmission $admission,
        private readonly FetchCommonsPhotoHandler $fetch,
        private readonly ContinentResolver $continents,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be localised and change nothing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many items and routes (a first run is worth keeping short).')
            ->addOption('letter', null, InputOption::VALUE_REQUIRED, 'Only this catalogue letter, e.g. Q for history & culture; R walks the recommended routes.')
            ->addOption(
                'recheck-licences',
                null,
                InputOption::VALUE_NONE,
                'First forget every past licence refusal, so files are re-judged against the current list. Only meaningful after LicenceUrls has grown.',
            )
            ->addOption(
                'sleep',
                null,
                InputOption::VALUE_REQUIRED,
                'Milliseconds to wait after each Wikimedia fetch (default 1000). 0 disables the pause.',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $this->intOption($input, 'limit');
        $letter = $input->getOption('letter');
        $letter = \is_string($letter) && '' !== $letter ? strtoupper($letter) : null;
        $pause = self::pauseMs($input);

        if ((bool) $input->getOption('recheck-licences') && !$dryRun) {
            $forgotten = $this->photos->requeueLicenceRefusals();
            if ($forgotten > 0) {
                $io->note(sprintf('Forgot %d past licence refusal%s: they will be judged again.', $forgotten, 1 === $forgotten ? '' : 's'));
            }
        }

        $rows = $this->hotlinkedRows($letter, $limit);
        if ([] === $rows) {
            $io->success('Nothing to localise: no catalogue item or route still points at Wikimedia.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d place%s still hotlinked', \count($rows), 1 === \count($rows) ? '' : 's'));
        if ($dryRun) {
            $io->note('Dry run: nothing is downloaded and nothing is written.');
        }

        $localised = 0;
        $reused = 0;
        $refused = 0;
        $notHere = 0;
        $unreadable = 0;
        $failed = 0;
        $dropped = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $route = 'route' === $row['kind'];
            $name = (string) ($row['name'] ?? '');
            $label = sprintf('%s#%d %s', $route ? 'route ' : '', $id, '' === $name ? '(unnamed)' : $name);
            $continent = null;   // resolved once, lazily, and only if something needs fetching
            $place = $route
                ? PhotoPlace::route($row['lat'], $row['lng'])
                : PhotoPlace::of($row['letter'], $row['lat'], $row['lng']);

            $single = $this->decodePhoto($row['photo']);
            $gallery = $this->decodeGallery($row['photos']);

            /** @var array<string, mixed>|false|null $newSingle null untouched, false removed */
            $newSingle = null;
            /** @var array<int, array<string, mixed>|null>|null $newGallery null untouched; a null entry is removed */
            $newGallery = null;

            /** @var list<array{index: int|null, photo: array<string, mixed>}> $slots */
            $slots = [];
            if (null !== $single) {
                $slots[] = ['index' => null, 'photo' => $single];
            }
            foreach ($gallery as $index => $entry) {
                $slots[] = ['index' => $index, 'photo' => $entry];
            }

            foreach ($slots as $slot) {
                $photo = $slot['photo'];
                $at = null === $slot['index'] ? $label : sprintf('%s [%d]', $label, $slot['index'] + 1);

                if (!str_contains((string) ($photo['sm'] ?? ''), 'wikimedia.org')) {
                    continue;   // a rider's upload, or one this run already localised
                }

                // What happens to an entry we cannot make ours. An item keeps
                // its link (the display filters hide what may not be shown, and
                // --recheck-licences can still reach it); a route never keeps a
                // hotlink, so the entry is taken off it.
                $outcome = $route ? ($dryRun ? ', would be dropped' : ', dropped') : ', left as a link';

                $file = CommonsFile::fromTags(['image' => (string) ($photo['sm'] ?? '')]);
                if (null === $file) {
                    ++$unreadable;
                    $io->writeln(sprintf('  <comment>?</comment> %s: the stored URL names no Commons file%s', $at, $outcome));
                    $this->dropSlot($route && !$dryRun, $slot['index'], $gallery, $newSingle, $newGallery, $dropped);
                    continue;
                }

                $ready = $this->admission->readyPhoto($file);
                if (null !== $ready) {
                    // Somebody else's fetch already brought this file in, most
                    // likely the same photo hanging off a coverage POI. It is
                    // still judged against THIS place before it is written.
                    $verdict = PhotoValidator::verdict(PhotoFacts::fromEntry($ready), $place);
                    if (!$verdict->shows()) {
                        ++$notHere;
                        $io->writeln(sprintf('  <comment>-</comment> %s: %s is ours but not shown here (%s)%s', $at, $file, null !== $verdict->reason ? $verdict->reason->value : 'refused', $outcome));
                        $this->dropSlot($route && !$dryRun, $slot['index'], $gallery, $newSingle, $newGallery, $dropped);
                        continue;
                    }
                    ++$reused;
                    $io->writeln(sprintf('  <info>=</info> %s: already in our storage', $at));
                } elseif ($dryRun) {
                    ++$localised;
                    $io->writeln(sprintf('  <info>+</info> %s: would fetch %s', $at, $file));
                    continue;
                } else {
                    // The row's own country first: it is a stored fact, where the
                    // centroid is a lookup that misses on any coastal shape.
                    $continent ??= $this->continents->forCountry(\is_string($row['country_code']) ? $row['country_code'] : null)
                        ?? $this->continents->resolve(
                            is_numeric($row['lat']) ? (float) $row['lat'] : null,
                            is_numeric($row['lng']) ? (float) $row['lng'] : null,
                        );
                    if (null === $continent) {
                        ++$failed;
                        $io->writeln(sprintf('  <error>x</error> %s: no continent for its coordinates, so no bucket', $at));
                        continue;
                    }

                    if (!$this->photos->claim($file)) {
                        // Declined for another place earlier: put it back for this one.
                        $this->photos->reopen($file);
                    }
                    $this->fetch->__invoke(FetchCommonsPhoto::forPlace($file, $continent, $place));
                    self::pause($pause);

                    $ready = $this->admission->readyPhoto($file);
                    if (null === $ready) {
                        $state = $this->photos->find($file);
                        $status = (string) ($state['state'] ?? 'unknown');
                        $why = (string) ($state['failed_reason'] ?? $status);
                        if (CommonsPhotoState::Unusable->value === $status) {
                            // PhotoValidator said no about the file: we may not
                            // republish it.
                            ++$refused;
                            $io->writeln(sprintf('  <comment>-</comment> %s: %s refused (%s)%s', $at, $file, $why, $outcome));
                            $this->dropSlot($route, $slot['index'], $gallery, $newSingle, $newGallery, $dropped);
                        } elseif (CommonsPhotoState::Declined->value === $status) {
                            ++$notHere;
                            $io->writeln(sprintf('  <comment>-</comment> %s: %s not shown here (%s), nothing downloaded%s', $at, $file, $why, $outcome));
                            $this->dropSlot($route, $slot['index'], $gallery, $newSingle, $newGallery, $dropped);
                        } else {
                            // Our problem (Commons down, no bucket): kept for the next run.
                            ++$failed;
                            $io->writeln(sprintf('  <error>x</error> %s: %s could not be fetched (%s)', $at, $file, $why));
                        }
                        continue;
                    }
                    if (!PhotoValidator::verdict(PhotoFacts::fromEntry($ready), $place)->shows()) {
                        ++$notHere;
                        $io->writeln(sprintf('  <comment>-</comment> %s: %s is ours but not shown here%s', $at, $file, $outcome));
                        $this->dropSlot($route, $slot['index'], $gallery, $newSingle, $newGallery, $dropped);
                        continue;
                    }

                    ++$localised;
                    $io->writeln(sprintf('  <info>+</info> %s: localised', $at));
                }

                if ($dryRun) {
                    continue;
                }

                $merged = $this->merge($photo, $ready);
                if (null === $slot['index']) {
                    $newSingle = $merged;
                } else {
                    $newGallery ??= $gallery;
                    $newGallery[$slot['index']] = $merged;
                }
            }

            if (null !== $newSingle || null !== $newGallery) {
                $this->write($route ? 'recommended_route' : 'item', $id, $newSingle, $newGallery);
            }
        }

        $io->newLine();
        $io->definitionList(
            ['localised' => (string) $localised],
            ['already ours' => (string) $reused],
            ['refused (licence, author, Commons flags)' => (string) $refused],
            ['not shown on this place (scenic camera)' => (string) $notHere],
            ['unreadable URL' => (string) $unreadable],
            ['failed' => (string) $failed],
            ['taken off a route (a route keeps no hotlink)' => (string) $dropped],
        );

        if ($dryRun) {
            $io->note('Nothing was written. Drop --dry-run to do it.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d photo%s now served from our own storage.', $localised + $reused, 1 === $localised + $reused ? '' : 's'));

        return Command::SUCCESS;
    }

    /**
     * How long to wait after each fetch, in milliseconds.
     *
     * Wikimedia gives its bandwidth away and asks clients to come one at a
     * time and unhurried. A backfill is the exact shape of request that abuses
     * that: hundreds of files, back to back, from one address, for a job with
     * no deadline. A second between them costs us nothing and is the
     * difference between a well-behaved client and a scrape.
     */
    private static function pauseMs(InputInterface $input): int
    {
        $raw = $input->getOption('sleep');
        if (!\is_string($raw) || !ctype_digit($raw)) {
            return 1000;
        }

        return min((int) $raw, 60_000);
    }

    private static function pause(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * Replace the URLs and the attribution, keep everything else the entry had.
     *
     * `alt` is the one that matters today: it is written by hand and there is
     * nowhere else to get it back from. `state` is dropped because it means
     * "this poll is finished", which is a thing the drawer asks the endpoint,
     * never a property of a stored photo.
     *
     * @param array<string, mixed> $stored the photo as the item carries it now
     * @param array<string, mixed> $ready  CommonsPhotoAdmission::readyPhoto()
     *
     * @return array<string, mixed>
     */
    private function merge(array $stored, array $ready): array
    {
        unset($ready['state']);

        return array_merge($stored, $ready);
    }

    /**
     * Every item and route whose photo still points at Wikimedia, items first.
     *
     * `--letter` narrows to one letter; routes are letter R.
     *
     * @return list<array<string, mixed>>
     */
    private function hotlinkedRows(?string $letter, ?int $limit): array
    {
        $parts = [];
        $params = [];
        if (null === $letter || ItemType::QualityRides->letter() !== $letter) {
            $sql = self::ITEM_SQL.' '.self::HOTLINK_WHERE;
            if (null !== $letter) {
                $sql .= ' AND t.letter = :letter';
                $params['letter'] = $letter;
            }
            $parts[] = $sql;
        }
        if (null === $letter || ItemType::QualityRides->letter() === $letter) {
            $parts[] = self::ROUTE_SQL.' '.self::HOTLINK_WHERE;
        }

        $sql = 'SELECT * FROM ('.implode(' UNION ALL ', array_map(static fn (string $p): string => '('.$p.')', $parts)).') AS hotlinked ORDER BY kind, id';
        if (null !== $limit) {
            $sql .= ' LIMIT '.$limit;
        }

        return $this->db->fetchAllAssociative($sql, $params);
    }

    /**
     * Take one entry off a route: the single `photo`, or a gallery position.
     *
     * Only when `$drop` is true, which is a route outside a dry run; an item
     * keeps its link.
     *
     * @param list<array<string, mixed>>                 $gallery
     * @param array<string, mixed>|false|null            $newSingle
     * @param array<int, array<string, mixed>|null>|null $newGallery
     */
    private function dropSlot(bool $drop, ?int $index, array $gallery, array|false|null &$newSingle, ?array &$newGallery, int &$dropped): void
    {
        if (!$drop) {
            return;
        }
        ++$dropped;
        if (null === $index) {
            $newSingle = false;

            return;
        }
        $newGallery ??= $gallery;
        $newGallery[$index] = null;
    }

    /**
     * One statement per row, whichever of the two shapes changed.
     *
     * `$photo` false removes the single entry; a null gallery entry is removed,
     * and a gallery left empty is removed, so a reader sees the same shape as a
     * row that never had one.
     *
     * @param 'item'|'recommended_route'                 $table
     * @param array<string, mixed>|false|null            $photo
     * @param array<int, array<string, mixed>|null>|null $photos
     */
    private function write(string $table, int $id, array|false|null $photo, ?array $photos): void
    {
        $sets = ['updated_at = NOW()'];
        $params = ['id' => $id];
        $attributes = 'attributes';

        if (false === $photo) {
            $attributes = "({$attributes} - 'photo')";
        } elseif (null !== $photo) {
            $attributes = "jsonb_set({$attributes}, '{photo}', :photo::jsonb)";
            $params['photo'] = self::encode($photo);
        }
        if (null !== $photos) {
            $kept = array_values(array_filter($photos, static fn (?array $entry): bool => null !== $entry));
            if ([] === $kept) {
                $attributes = "({$attributes} - 'photos')";
            } else {
                $attributes = "jsonb_set({$attributes}, '{photos}', :photos::jsonb)";
                $params['photos'] = self::encode($kept);
            }
        }

        array_unshift($sets, 'attributes = '.$attributes);
        $this->db->executeStatement('UPDATE '.$table.' SET '.implode(', ', $sets).' WHERE id = :id', $params);
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * The gallery, or an empty list when the row has none.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeGallery(mixed $raw): array
    {
        if (!\is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (\is_array($entry) && isset($entry['sm'])) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function decodePhoto(mixed $raw): ?array
    {
        if (!\is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) && isset($decoded['sm']) ? $decoded : null;
    }

    private function intOption(InputInterface $input, string $name): ?int
    {
        $raw = $input->getOption($name);

        return \is_string($raw) && ctype_digit($raw) && $raw > 0 ? (int) $raw : null;
    }
}
