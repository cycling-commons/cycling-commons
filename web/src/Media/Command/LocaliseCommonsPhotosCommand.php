<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

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
 * Bring the catalogue's remaining Commons hotlinks into our own storage.
 *
 * Two ways a photo reaches a rider used to exist side by side. A town card and
 * a coverage POI have no photo until somebody opens them, so the code goes and
 * finds one at request time, and that path downloads the file, re-encodes it
 * and keeps it in our bucket. A seeded or harvested catalogue item is the other
 * shape: `SeedWikidataPlacesCommand` and the Wallonia enrich step turned an OSM
 * `wikimedia_commons` tag into a `Special:FilePath` URL and stored the URL, so
 * the drawer printed it and the rider's browser fetched the pixels from
 * Wikimedia. Nothing was ever cached, because nothing ever asked.
 *
 * That was leftover rather than a decision: the cache landed after the seeders
 * had already written their URLs, and nobody went back. It cost us three
 * things. Wikimedia sees a request per rider per photo. The file can be renamed
 * or deleted there and our page silently loses its picture. And the URL is a
 * redirect chain we do not control: when thumbnails moved to
 * `thumb.wikimedia.org` in 2026, every one of those photos went blank behind
 * our own `img-src` and nothing on our side had changed.
 *
 * This walks those rows and puts each file through the SAME path a town card
 * uses, handler and all: PhotoValidator first, judged against the item's own
 * letter and pin, then download, virus scan, re-encode to three webp sizes,
 * store. Only when PhotoValidator shows the stored copy on that item is
 * `item.attributes->photo` rewritten to our URLs, carrying the credit, the
 * uploader's Commons page, the licence and the file's Commons page with it.
 * That last part is not decoration: CC BY-SA is satisfied only while the
 * attribution travels with the copy, which is why the shape comes from
 * {@see CommonsPhotoAdmission::readyPhoto()} rather than being written twice.
 *
 * A file PhotoValidator refuses is left exactly as it was, and the report names
 * the reason (licence, no_author, non_free, camera_far and so on). We do not
 * republish what we may not republish, and a hotlink is not a copy; a hotlink
 * a place may not show is hidden by every display filter and removed by
 * `app:scenic:prune-photos`.
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
    description: 'Download the catalogue\'s remaining Commons hotlinks into our own storage',
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
    private const string HOTLINK_SQL = <<<'SQL'
        SELECT id, letter, name, country_code,
               ST_Y(ST_PointOnSurface(geom)) AS lat, ST_X(ST_PointOnSurface(geom)) AS lng,
               attributes->'photo' AS photo, attributes->'photos' AS photos
        FROM item
        WHERE attributes->'photo'->>'sm' LIKE '%wikimedia.org%'
           OR EXISTS (
                SELECT 1 FROM jsonb_array_elements(
                    CASE WHEN jsonb_typeof(attributes->'photos') = 'array'
                         THEN attributes->'photos' ELSE '[]'::jsonb END
                ) AS g
                WHERE g->>'sm' LIKE '%wikimedia.org%'
           )
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many items (a first run is worth keeping short).')
            ->addOption('letter', null, InputOption::VALUE_REQUIRED, 'Only this catalogue letter, e.g. Q for history & culture.')
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

        $sql = self::HOTLINK_SQL;
        $params = [];
        if (null !== $letter) {
            $sql .= ' AND letter = :letter';
            $params['letter'] = $letter;
        }
        $sql .= ' ORDER BY id';
        if (null !== $limit) {
            $sql .= ' LIMIT '.$limit;
        }

        $rows = $this->db->fetchAllAssociative($sql, $params);
        if ([] === $rows) {
            $io->success('Nothing to localise: no catalogue item still points at Wikimedia.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d item%s still hotlinked', \count($rows), 1 === \count($rows) ? '' : 's'));
        if ($dryRun) {
            $io->note('Dry run: nothing is downloaded and nothing is written.');
        }

        $localised = 0;
        $reused = 0;
        $refused = 0;
        $notHere = 0;
        $unreadable = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $name = (string) ($row['name'] ?? '');
            $label = sprintf('#%d %s', $id, '' === $name ? '(unnamed)' : $name);
            $continent = null;   // resolved once, lazily, and only if something needs fetching
            $place = PhotoPlace::of($row['letter'], $row['lat'], $row['lng']);

            $single = $this->decodePhoto($row['photo']);
            $gallery = $this->decodeGallery($row['photos']);

            $newSingle = null;
            $newGallery = null;
            $touched = false;

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

                $file = CommonsFile::fromTags(['image' => (string) ($photo['sm'] ?? '')]);
                if (null === $file) {
                    ++$unreadable;
                    $io->writeln(sprintf('  <comment>?</comment> %s: the stored URL names no Commons file', $at));
                    continue;
                }

                $ready = $this->admission->readyPhoto($file);
                if (null !== $ready) {
                    // Somebody else's fetch already brought this file in, most
                    // likely the same photo hanging off a coverage POI. It is
                    // still judged against THIS item before it is written.
                    $verdict = PhotoValidator::verdict(PhotoFacts::fromEntry($ready), $place);
                    if (!$verdict->shows()) {
                        ++$notHere;
                        $io->writeln(sprintf('  <comment>-</comment> %s: %s is ours but not shown here (%s), left as it was', $at, $file, $verdict->reason?->value ?? 'refused'));
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
                            // PhotoValidator said no about the file. The hotlink stays: we
                            // may not republish the file, and linking to it is not republishing.
                            ++$refused;
                            $io->writeln(sprintf('  <comment>-</comment> %s: %s refused (%s), left as a link', $at, $file, $why));
                        } elseif (CommonsPhotoState::Declined->value === $status) {
                            ++$notHere;
                            $io->writeln(sprintf('  <comment>-</comment> %s: %s not shown here (%s), nothing downloaded', $at, $file, $why));
                        } else {
                            ++$failed;
                            $io->writeln(sprintf('  <error>x</error> %s: %s could not be fetched (%s)', $at, $file, $why));
                        }
                        continue;
                    }
                    if (!PhotoValidator::verdict(PhotoFacts::fromEntry($ready), $place)->shows()) {
                        ++$notHere;
                        $io->writeln(sprintf('  <comment>-</comment> %s: %s is ours but not shown here, left as it was', $at, $file));
                        continue;
                    }

                    ++$localised;
                    $io->writeln(sprintf('  <info>+</info> %s: localised', $at));
                }

                if ($dryRun) {
                    continue;
                }

                $merged = $this->merge($photo, $ready);
                $touched = true;
                if (null === $slot['index']) {
                    $newSingle = $merged;
                } else {
                    $newGallery ??= $gallery;
                    $newGallery[$slot['index']] = $merged;
                }
            }

            if ($touched) {
                $this->write($id, $newSingle, $newGallery);
            }
        }

        $io->newLine();
        $io->definitionList(
            ['localised' => (string) $localised],
            ['already ours' => (string) $reused],
            ['refused (licence, author, Commons flags)' => (string) $refused],
            ['not shown on this item (scenic camera)' => (string) $notHere],
            ['unreadable URL' => (string) $unreadable],
            ['failed' => (string) $failed],
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
     * One statement per item, whichever of the two shapes changed.
     *
     * @param array<string, mixed>|null       $photo
     * @param list<array<string, mixed>>|null $photos
     */
    private function write(int $id, ?array $photo, ?array $photos): void
    {
        $sets = ['updated_at = NOW()'];
        $params = ['id' => $id];
        $attributes = 'attributes';

        if (null !== $photo) {
            $attributes = "jsonb_set({$attributes}, '{photo}', :photo::jsonb)";
            $params['photo'] = self::encode($photo);
        }
        if (null !== $photos) {
            $attributes = "jsonb_set({$attributes}, '{photos}', :photos::jsonb)";
            $params['photos'] = self::encode($photos);
        }

        array_unshift($sets, 'attributes = '.$attributes);
        $this->db->executeStatement('UPDATE item SET '.implode(', ', $sets).' WHERE id = :id', $params);
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
