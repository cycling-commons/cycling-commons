<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\Entity\MediaUpload;
use App\Media\MediaStatus;
use App\Media\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The one-off move onto immutable keys
 * (docs/specs/media-storage-architecture.md §4.1; media plan task 5).
 *
 * Photos written before the release gate existed live at the MUTABLE
 * `photos/<uuid>/orig.webp|lg.webp|sm.webp` - a fixed set of names that
 * reprocessing would overwrite in place. This copies each set to
 * `published/<uuid>/<rev>/…`, stamps the revision on the row, and only then
 * removes the old prefix.
 *
 * **A copy, then the row, then the delete.** Any other order has a window in
 * which the row names objects that are not there, and a proxy that caches a
 * 404 for a year is a worse outcome than running the command twice: it is
 * idempotent, and a half-finished run simply finishes on the next one.
 *
 * The alternative - teaching the URL builder to recognise the old shape - was
 * considered and refused. A permanent legacy branch is exactly the
 * "tolerate the old shape" pattern the dead-code sweep spent a session
 * removing, and this is a handful of rows: no production photos exist yet,
 * which is why task 5 had to land before any real traffic.
 *
 * Rows whose objects are already gone (a tombstoned upload keeps its row past
 * its files, docs/specs/photo-uploads.md §6) are reported and skipped: there is
 * nothing to copy and stamping a revision would claim otherwise.
 *
 * @api Console entry point. Run once, immediately after Version20260816210000.
 */
#[AsCommand(name: 'app:media:backfill-keys', description: 'Move pre-quarantine photos onto immutable published/<uuid>/<rev>/ keys')]
final class MediaBackfillKeysCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would move and change nothing.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /* Quarantined rows are deliberately out of scope: they have no
           published objects to move, and their revision being NULL is the
           current state of a live upload, not a leftover. */
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.revision IS NULL AND m.status <> :quarantined
             ORDER BY m.createdAt ASC',
        )
            ->setParameter('quarantined', MediaStatus::PendingScan)
            ->getResult();

        if ([] === $rows) {
            $io->success('Nothing to backfill: every photo already has an immutable key.');

            return Command::SUCCESS;
        }

        $moved = 0;
        $empty = 0;
        foreach ($rows as $upload) {
            $uuid = $upload->getId()->toRfc4122();
            $legacy = 'photos/'.$uuid;
            $revision = MediaUpload::mintRevision();
            $target = MediaUpload::prefixFor($uuid, $revision);

            if ($dryRun) {
                $io->writeln(\sprintf('  %s  %s -> %s', $uuid, $legacy, $target));
                ++$moved;
                continue;
            }

            $copied = $this->storage->copyVariants($upload->getStorageShard(), $legacy, $target);
            if (0 === $copied) {
                $io->writeln(\sprintf('  %s  no objects found at %s - left alone', $uuid, $legacy));
                ++$empty;
                continue;
            }

            $upload->stampRevision($revision);
            $this->em->flush();

            // Only now, with the row pointing at objects that exist.
            $this->storage->deletePrefix($upload->getStorageShard(), $legacy);
            ++$moved;
        }

        $io->success(\sprintf(
            '%d photo(s) %s onto immutable keys; %d had no objects to move.',
            $moved,
            $dryRun ? 'would move' : 'moved',
            $empty,
        ));

        return Command::SUCCESS;
    }
}
