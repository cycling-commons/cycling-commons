<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use App\Media\MediaStorage;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\MessageHandler\FetchCommonsPhotoHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Put the rights packet into Commons photos stored before there was one.
 *
 * Until 2026-09-09 `FetchCommonsPhotoHandler` re-encoded a Commons file and
 * passed no XMP, and the re-encode drops whatever arrived, so every photo it
 * stored is an orphan: no author, no licence, nothing inside the bytes. The
 * caption on our page is right; the file is not, and the file is what somebody
 * downloads. CC BY-SA asks for the attribution to travel with the work
 * (photo-uploads.md §5f).
 *
 * Nothing else revisits them. `app:media:localise-commons` finds items still
 * pointing at Wikimedia, and these no longer do. `CommonsPhotoRepository::retry()`
 * only re-queues rows that are stuck, and a `ready` row is not stuck.
 *
 * The photo keeps its bucket and prefix, so every URL already published for it
 * stays valid and only the bytes behind it change
 * ({@see CommonsPhotoRepository::markForRestamp()}). Nothing outside this
 * command has to know it ran.
 *
 * Reads each stored file before deciding, rather than trusting a date: a
 * `ready_at` older than the fix is a guess, and the profile is the fact. So it
 * is safe to run repeatedly and reports zero once there is nothing left.
 *
 * @see docs/specs/photo-uploads.md §5f
 *
 * @api
 */
#[AsCommand(
    name: 'app:media:restamp-commons-rights',
    description: 'Re-fetch Commons photos stored without their rights packet, keeping their URLs',
)]
final class RestampCommonsRightsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly CommonsPhotoRepository $photos,
        private readonly MediaStorage $storage,
        private readonly FetchCommonsPhotoHandler $fetch,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report which stored files carry no packet and change nothing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many photos.')
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
        $pause = self::pauseMs($input);

        $sql = 'SELECT file, storage_bucket, storage_prefix FROM commons_photo'
            .' WHERE state = :ready AND storage_prefix IS NOT NULL ORDER BY id';
        $limit = $input->getOption('limit');
        if (\is_string($limit) && ctype_digit($limit) && $limit > 0) {
            $sql .= ' LIMIT '.(int) $limit;
        }

        $rows = $this->db->fetchAllAssociative($sql, ['ready' => CommonsPhotoState::Ready->value]);
        if ([] === $rows) {
            $io->success('No stored Commons photo to look at.');

            return Command::SUCCESS;
        }

        $stamped = 0;
        $already = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $file = (string) $row['file'];
            $bucket = (string) $row['storage_bucket'];
            $prefix = (string) $row['storage_prefix'];

            if (self::hasRightsPacket($this->readLarge($bucket, $prefix))) {
                ++$already;
                continue;
            }

            if ($dryRun) {
                ++$stamped;
                $io->writeln(sprintf('  <info>+</info> %s: would be re-fetched with its rights packet', $file));
                continue;
            }

            if (!$this->photos->markForRestamp($file)) {
                ++$failed;
                $io->writeln(sprintf('  <error>x</error> %s: could not be queued (state changed under us)', $file));
                continue;
            }

            // The continent is only read when a row has no bucket yet, which is
            // never true here; the handler keeps the bucket this photo is in.
            $this->fetch->__invoke(new FetchCommonsPhoto($file, 'EU'));
            self::pause($pause);

            // The row, not the bytes. Whether the stored file comes back with a
            // packet is the handler's contract and is pinned there
            // (FetchCommonsPhotoHandlerTest::testTheStoredFileCarriesTheCommonsRightsPacket);
            // re-reading the object here would only restate it, and a photo
            // that failed leaves the row saying so.
            $after = $this->photos->find($file);
            if (CommonsPhotoState::Ready->value !== ($after['state'] ?? '')) {
                ++$failed;
                $io->writeln(sprintf('  <error>x</error> %s: re-fetch did not produce a stamped file (%s)', $file, (string) ($after['state'] ?? 'gone')));
                continue;
            }

            ++$stamped;
            $io->writeln(sprintf('  <info>+</info> %s: re-stamped', $file));
        }

        $io->newLine();
        $io->definitionList(
            ['re-stamped' => (string) $stamped],
            ['already carried a packet' => (string) $already],
            ['failed' => (string) $failed],
        );

        if ($dryRun) {
            $io->note('Nothing was written. Drop --dry-run to do it.');
        }

        return Command::SUCCESS;
    }

    /**
     * How long to wait after each fetch, in milliseconds.
     *
     * Wikimedia gives its bandwidth away and asks clients to come one at a time
     * and unhurried. A backfill is the exact shape of request that abuses that:
     * every stored photo, back to back, from one address, for a job with no
     * deadline. A second between them costs us nothing.
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
     * The published large variant's bytes, or null when it cannot be read.
     *
     * `lg`, because `sm` deliberately never carries a packet (a 1.4 KB rights
     * block on a 520px preview is most of the file) and would report every
     * photo as unstamped forever.
     *
     * Split from the check so the two are separate steps: the whole point of
     * reading again after a re-fetch is that the bytes have changed, and a
     * check keyed on the bucket and prefix alone looks like a question with a
     * fixed answer.
     */
    private function readLarge(string $bucket, string $prefix): ?string
    {
        $stream = $this->storage->readStream($bucket, $prefix, 'lg');
        if (!\is_resource($stream)) {
            return null;
        }
        $bytes = stream_get_contents($stream);

        return \is_string($bytes) && '' !== $bytes ? $bytes : null;
    }

    /** Does this image carry an XMP profile? */
    private static function hasRightsPacket(?string $bytes): bool
    {
        if (null === $bytes) {
            return false;
        }

        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $profiles = $image->getImageProfiles('xmp', false);
            $image->clear();

            return [] !== $profiles;
        } catch (\ImagickException) {
            return false;
        }
    }
}
