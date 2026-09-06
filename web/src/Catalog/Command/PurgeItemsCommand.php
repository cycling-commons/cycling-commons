<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Media\Entity\MediaUpload;
use App\Media\MediaDisposalService;
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
 * Removes an item and everything that hangs off it: its photos (stored files
 * and rows, through the same disposal the trash uses), the submissions that
 * made or changed it, its on-the-spot checks and its change history.
 *
 * For test rows that reached the catalogue, not for moderation: a curator
 * who wants a place gone rejects it on the desk, where the author is told.
 * Dry run by default; --force writes. A photo under legal hold is left
 * standing and named, and its item is left with it.
 */
#[AsCommand(name: 'app:items:purge', description: 'Delete items outright with their photos, submissions, checks and history (dry run unless --force)')]
final class PurgeItemsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly MediaDisposalService $disposal,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Item id (repeatable)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete (default is a dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        /** @var list<string> $raw */
        $raw = $input->getOption('id');
        $ids = array_values(array_unique(array_map(intval(...), $raw)));
        if ([] === $ids) {
            $io->error('Give at least one --id.');

            return Command::INVALID;
        }

        $rows = [];
        $held = [];
        $this->db->beginTransaction();
        try {
            foreach ($ids as $id) {
                $item = $this->db->fetchAssociative('SELECT id, name, letter, source, source_ref FROM item WHERE id = :id', ['id' => $id]);
                if (false === $item) {
                    $rows[] = [$id, '(no such item)', '', '', '', ''];
                    continue;
                }
                $subs = array_map(intval(...), $this->db->fetchFirstColumn('SELECT id FROM submission WHERE item_id = :id', ['id' => $id]));

                $uploads = $this->uploadsFor($id, $subs);
                $photos = 0;
                foreach ($uploads as $upload) {
                    if ($upload->isEscalated()) {
                        $held[] = sprintf('%s on item %d (%s)', $upload->getId()->toRfc4122(), $id, (string) $item['name']);
                        continue 2;
                    }
                    if ($force) {
                        $this->disposal->purge($upload);
                    }
                    ++$photos;
                }

                $checks = (int) $this->db->fetchOne('SELECT COUNT(*) FROM item_confirmation WHERE item_id = :id', ['id' => $id]);
                $history = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM change_history WHERE item_id = :id OR submission_id IN (:subs)',
                    ['id' => $id, 'subs' => [] === $subs ? [0] : $subs],
                    ['subs' => ArrayParameterType::INTEGER],
                );
                if ($force) {
                    $this->em->flush();
                    $this->db->executeStatement('DELETE FROM item_confirmation WHERE item_id = :id', ['id' => $id]);
                    $this->db->executeStatement(
                        'DELETE FROM change_history WHERE item_id = :id OR submission_id IN (:subs)',
                        ['id' => $id, 'subs' => [] === $subs ? [0] : $subs],
                        ['subs' => ArrayParameterType::INTEGER],
                    );
                    $this->db->executeStatement('DELETE FROM submission WHERE item_id = :id', ['id' => $id]);
                    $this->db->executeStatement('DELETE FROM item WHERE id = :id', ['id' => $id]);
                }
                $rows[] = [$id, (string) $item['name'], (string) $item['source_ref'], $photos, \count($subs), $checks.' / '.$history];
            }
            if ($force) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $io->table(['id', 'name', 'ref', 'photos', 'submissions', 'checks / history'], $rows);
        if ([] !== $held) {
            $io->warning("Left standing, photo under legal hold:\n".implode("\n", $held));
        }
        $io->{$force ? 'success' : 'note'}($force ? 'Deleted.' : 'Dry run: nothing deleted. Add --force.');

        return Command::SUCCESS;
    }

    /**
     * The item's photos, and the photos of its submissions that never got an
     * item id (a rejected upload keeps only its submission).
     *
     * @param list<int> $subs
     *
     * @return list<MediaUpload>
     */
    private function uploadsFor(int $itemId, array $subs): array
    {
        $qb = $this->em->createQueryBuilder()->select('m')->from(MediaUpload::class, 'm')
            ->where('m.itemId = :id')->setParameter('id', $itemId);
        if ([] !== $subs) {
            $qb->orWhere('m.submissionId IN (:subs)')->setParameter('subs', $subs);
        }

        /** @var list<MediaUpload> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
