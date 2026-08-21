<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\Entity\MediaUpload;
use App\Media\MediaStorage;
use App\Media\ShardUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fill media_upload.storage_bucket for rows that predate the column.
 *
 * The migration that added storage_bucket defaulted it to '' and backfilled
 * nothing, while MediaStorage::url() rejects any name not ending in -<cc>-<nn>.
 * An unfilled row therefore throws the moment a page renders its photo - a 500,
 * not a missing image. Every environment carrying pre-column rows needs this
 * run once.
 *
 * The bucket is derived from the row's continent, which is exactly how the
 * upload path chose it, so this records the address rather than inventing one.
 *
 * @see docs/specs/media-storage-architecture.md §2.1
 *
 * @api
 */
#[AsCommand(
    name: 'app:media:backfill-storage-bucket',
    description: 'Fill media_upload.storage_bucket on rows that predate the column',
)]
final class MediaBackfillStorageBucketCommand extends Command
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
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing.')
            ->addOption(
                'skip-verify',
                null,
                InputOption::VALUE_NONE,
                'Do not check that the objects are really in the derived bucket. '
                .'Only for a run against storage that is not reachable from here.',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $verify = !(bool) $input->getOption('skip-verify');

        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class." m
             WHERE m.storageBucket = ''
             ORDER BY m.createdAt ASC",
        )->getResult();

        if ([] === $rows) {
            $io->success('Nothing to backfill: every row already records its bucket.');

            return Command::SUCCESS;
        }

        // Resolve every continent up front. A half-finished backfill is worse
        // than none, so an unconfigured shard stops the run before any write.
        $buckets = [];
        $missing = [];
        foreach ($rows as $upload) {
            $continent = $upload->getContinent();
            if (isset($buckets[$continent]) || isset($missing[$continent])) {
                continue;
            }
            try {
                $buckets[$continent] = $this->storage->bucketFor($continent);
            } catch (ShardUnavailable|\InvalidArgumentException $e) {
                $missing[$continent] = $e->getMessage();
            }
        }

        if ([] !== $missing) {
            $io->error(\sprintf('%d row(s) need a bucket that is not configured:', \count($rows)));
            foreach ($missing as $continent => $why) {
                $io->writeln(\sprintf('  %s  %s', $continent, $why));
            }
            $io->writeln('Set the matching MEDIA_S3_PUBLIC_BUCKET_<CC> and run again.');

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('%d row(s) to fill:', \count($rows)));
        foreach ($buckets as $continent => $bucket) {
            $io->writeln(\sprintf('  %s -> %s', $continent, $bucket));
        }

        $filled = 0;
        $absent = 0;
        foreach ($rows as $upload) {
            $bucket = $buckets[$upload->getContinent()];

            // A rejected row's objects are deleted on purpose; there is nothing
            // to find and its address is still worth recording.
            $expectObjects = $upload->hasPublishedObjects() && null === $upload->getObjectsDeletedAt();

            if ($verify && $expectObjects) {
                $found = $this->storage->variantsExist($bucket, $upload->getPathPrefix());
                if (0 === $found) {
                    $io->warning(\sprintf(
                        '%s: no objects under %s in %s - left blank. Copy the objects there first, or pass --skip-verify.',
                        $upload->getId()->toRfc4122(),
                        $upload->getPathPrefix(),
                        $bucket,
                    ));
                    ++$absent;
                    continue;
                }
            }

            if ($dryRun) {
                ++$filled;
                continue;
            }

            $upload->adoptStorageBucket($bucket);
            ++$filled;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        if ($absent > 0) {
            $io->warning(\sprintf('%d row(s) still blank because their objects were not found.', $absent));
        }

        $io->success(\sprintf(
            '%d row(s) %s.',
            $filled,
            $dryRun ? 'would be filled' : 'filled',
        ));

        return $absent > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
