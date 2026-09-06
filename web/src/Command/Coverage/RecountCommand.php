<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Command\Coverage;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds coverage_count from the coverage rows in one pass.
 *
 * The triggers keep it right on every change; this is for the day somebody
 * loaded rows with triggers off, or wants proof. It is the slow walk, once.
 *
 * @see docs/specs/coverage-provider.md §11
 *
 * @api
 */
#[AsCommand(name: 'app:coverage:recount', description: 'Rebuild the kept coverage counts from the coverage rows (slow, once)')]
final class RecountCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $started = microtime(true);
        $this->db->executeStatement('SELECT coverage_count_install()');
        $this->db->executeStatement('SELECT coverage_count_rebuild()');
        $buckets = (int) $this->db->fetchOne('SELECT COUNT(*) FROM coverage_count');
        $total = (int) $this->db->fetchOne('SELECT COALESCE(SUM(n), 0) FROM coverage_count');
        $io->success(sprintf('%d buckets, %d shown coverage rows, %.1f s.', $buckets, $total, microtime(true) - $started));

        return Command::SUCCESS;
    }
}
