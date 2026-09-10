<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * One-off move onto immutable keys: copy, then row, then delete.
 *
 * @see docs/specs/media-storage-architecture.md §4.1
 *
 * @api
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

        // Quarantined rows are out of scope: NULL revision is a live upload, not a leftover.
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

            $copied = $this->storage->copyVariants($upload->getStorageBucket(), $legacy, $target);
            if (0 === $copied) {
                $io->writeln(\sprintf('  %s  no objects found at %s - left alone', $uuid, $legacy));
                ++$empty;
                continue;
            }

            $upload->stampRevision($revision);
            $this->em->flush();

            // Only now, with the row pointing at objects that exist.
            $this->storage->deletePrefix($upload->getStorageBucket(), $legacy);
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
