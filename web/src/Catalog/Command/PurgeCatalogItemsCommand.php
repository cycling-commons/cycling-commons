<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Media\Commons\CommonsPhotoUsage;
use App\Media\Entity\MediaUpload;
use App\Media\MediaDisposalService;
use App\Media\MediaStorage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Permanently remove retired catalog items, every record about them, and the
 * photos only they use.
 *
 * Only `retired` items: a live item is never removed by a bulk command, and
 * retiring first is the decision a person can still reverse. With `--write`:
 *
 * - the item, and by cascade its catalog findings;
 * - its change history, confirmations and content reports;
 * - the submissions about it and the messages sent about those submissions;
 * - the rider uploads attached to it, objects included (MediaDisposalService);
 * - each Commons photo on it that no remaining item and no coverage point still
 *   names: the stored objects and the `commons_photo` row. A photo another
 *   place uses stays, because deleting it would take that place's photo too.
 *
 * Without `--write` it reports what it would remove and changes nothing.
 *
 * @see docs/specs/scenic-views.md §4
 *
 * @api
 */
#[AsCommand(name: 'app:catalog:purge-items', description: 'Permanently remove retired catalog items and the photos only they use')]
final class PurgeCatalogItemsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly MediaDisposalService $disposal,
        private readonly CommonsPhotoUsage $usage,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('letter', null, InputOption::VALUE_REQUIRED, 'Catalog letter, e.g. P')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Must be retired')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Remove for real. Without it, nothing changes.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $letter = strtoupper((string) $input->getOption('letter'));
        $state = (string) $input->getOption('state');
        $write = (bool) $input->getOption('write');

        if (1 !== preg_match('/^[A-Z]$/', $letter)) {
            $io->error('Pass --letter with one catalog letter.');

            return Command::INVALID;
        }
        if ('retired' !== $state) {
            $io->error('Only retired items can be purged. Retire an item first; that is the decision a person can still reverse.');

            return Command::INVALID;
        }

        /** @var list<array{id: int|string, name: string, attributes: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, name, attributes FROM item WHERE letter = :l AND state = 'retired' ORDER BY name",
            ['l' => $letter],
        );
        if ([] === $rows) {
            $io->success('No retired items to remove.');

            return Command::SUCCESS;
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        $files = [];
        foreach ($rows as $row) {
            $attributes = json_decode((string) $row['attributes'], true);
            foreach (\is_array($attributes) ? CommonsPhotoUsage::filesIn($attributes) : [] as $file) {
                $files[$file] = true;
            }
        }
        $files = array_map('strval', array_keys($files));
        // A file stays when an item outside the purge, or any coverage point, still names it.
        $named = $this->usage->namedByItems($files, $ids);
        $points = $this->usage->coveragePoints($files);
        $orphans = array_values(array_filter($files, static fn (string $f): bool => !isset($named[$f]) && !isset($points[$f])));
        $uploads = $this->db->fetchFirstColumn('SELECT id FROM media_upload WHERE item_id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);

        $io->section(\sprintf('%s %d retired %s item(s)', $write ? 'Removing' : 'Would remove', \count($ids), $letter));
        $io->listing(array_map(static fn (array $r): string => \sprintf('%s (#%d)', $r['name'], (int) $r['id']), $rows));
        $io->writeln(\sprintf(
            '%d Commons photo(s) only these items use, %d still used elsewhere and kept; %d rider upload(s).',
            \count($orphans), \count($files) - \count($orphans), \count($uploads),
        ));

        if (!$write) {
            $io->note('Dry run: nothing changed. Re-run with --write to remove them permanently.');

            return Command::SUCCESS;
        }

        /** @var list<array{bucket: string, prefix: string}> $objects */
        $objects = [];
        $this->db->transactional(function (Connection $db) use ($ids, $orphans, &$objects): void {
            $types = ['ids' => ArrayParameterType::INTEGER];
            $submissions = $db->fetchFirstColumn('SELECT id FROM submission WHERE item_id IN (:ids)', ['ids' => $ids], $types);
            if ([] !== $submissions) {
                $db->executeStatement("DELETE FROM user_message WHERE channel = 'submission' AND ref_id IN (:s)",
                    ['s' => $submissions], ['s' => ArrayParameterType::INTEGER]);
                $db->executeStatement('DELETE FROM submission WHERE id IN (:s)', ['s' => $submissions], ['s' => ArrayParameterType::INTEGER]);
            }
            $db->executeStatement('DELETE FROM change_history WHERE item_id IN (:ids)', ['ids' => $ids], $types);
            $db->executeStatement('DELETE FROM item_confirmation WHERE item_id IN (:ids)', ['ids' => $ids], $types);
            $db->executeStatement("DELETE FROM content_report WHERE target_type = 'item' AND target_id IN (:t)",
                ['t' => array_map('strval', $ids)], ['t' => ArrayParameterType::STRING]);
            if ([] !== $orphans) {
                /** @var list<array{storage_bucket: ?string, storage_prefix: ?string}> $stored */
                $stored = $db->fetchAllAssociative('SELECT storage_bucket, storage_prefix FROM commons_photo WHERE file IN (:f)',
                    ['f' => $orphans], ['f' => ArrayParameterType::STRING]);
                foreach ($stored as $s) {
                    if (\is_string($s['storage_bucket']) && \is_string($s['storage_prefix']) && '' !== $s['storage_prefix']) {
                        $objects[] = ['bucket' => $s['storage_bucket'], 'prefix' => $s['storage_prefix']];
                    }
                }
                $db->executeStatement('DELETE FROM commons_photo WHERE file IN (:f)', ['f' => $orphans], ['f' => ArrayParameterType::STRING]);
            }
            // Catalog findings go with the item by cascade.
            $db->executeStatement("DELETE FROM item WHERE id IN (:ids) AND state = 'retired'", ['ids' => $ids], $types);
        });

        foreach ($this->db->fetchFirstColumn('SELECT id FROM media_upload WHERE item_id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]) as $uploadId) {
            $upload = $this->em->find(MediaUpload::class, $uploadId);
            if ($upload instanceof MediaUpload) {
                $this->disposal->purge($upload);
            }
        }
        $this->em->flush();

        // Objects last: a failed database step must never leave rows pointing at deleted files.
        foreach ($objects as $o) {
            $this->storage->deletePrefix($o['bucket'], $o['prefix']);
        }

        $io->success(\sprintf('Removed %d item(s) and %d photo(s).', \count($ids), \count($objects)));

        return Command::SUCCESS;
    }
}
