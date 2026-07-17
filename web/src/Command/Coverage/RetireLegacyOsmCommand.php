<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Command\Coverage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retires the legacy imported-OSM item rows the coverage cache now serves
 * (coverage-provider.md §9): letters C–J only,
 * source=osm, state=unverified, and ZERO human touches — no change_history,
 * no item_confirmation, no submission referencing the row. Anything a human
 * ever touched stays canonical. A (road surface) is letter-exempt because it
 * never entered the coverage artifact
 * (coverage-provider.md §7); B (climbs) is
 * wikidata-sourced and off-predicate anyway.
 *
 * The predicate mirrors CatalogProvider::itemRows()'s $excludeCoverageServed
 * clause — what COVERAGE_TILES=1 hides is exactly what this deletes.
 *
 * Dry-run by default: prints per-letter counts, changes nothing. The
 * destructive run (--force) is owner-gated — explicit approval plus a dev-DB
 * backup (developers/docker/backups/) before running it, per standing rule.
 *
 * @api Console entry point (one-off migration; safe to re-run — a second
 *      --force run always reports nothing left to retire).
 */
#[AsCommand(name: 'app:coverage:retire-legacy', description: 'Retire imported-OSM item rows the coverage cache now serves (dry-run by default; delete with --force)')]
final class RetireLegacyOsmCommand extends Command
{
    /**
     * Retirement predicate over `item` (coverage-provider.md
     * §9) — keep in sync with CatalogProvider::itemRows().
     */
    private const string PREDICATE = <<<'SQL'
        letter IN ('C', 'D', 'E', 'G', 'H', 'I', 'J')
        AND source = 'osm'
        AND state = 'unverified'
        AND NOT EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id)
        AND NOT EXISTS (SELECT 1 FROM item_confirmation ic WHERE ic.item_id = item.id)
        AND NOT EXISTS (SELECT 1 FROM submission sb WHERE sb.item_id = item.id)
        SQL;

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete the rows (without it: dry-run count report only)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<array{letter: string, n: int|string}> $counts */
        $counts = $this->db->fetchAllAssociative(
            'SELECT letter, COUNT(*) AS n FROM item WHERE '.self::PREDICATE.' GROUP BY letter ORDER BY letter',
        );
        if ([] === $counts) {
            $io->success('Nothing to retire — no coverage-served legacy OSM rows found.');

            return Command::SUCCESS;
        }

        $total = 0;
        foreach ($counts as $row) {
            $io->writeln(sprintf('  %s: %d row(s)', $row['letter'], (int) $row['n']));
            $total += (int) $row['n'];
        }

        if (!$input->getOption('force')) {
            $io->note(sprintf('Dry-run: %d row(s) would be deleted. Re-run with --force to delete them (owner approval + dev-DB backup first).', $total));

            return Command::SUCCESS;
        }

        try {
            $this->db->beginTransaction();
            $deleted = $this->db->executeStatement('DELETE FROM item WHERE '.self::PREDICATE);
            $this->db->commit();
        } catch (DBALException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Deleted %d legacy OSM row(s) now served by the coverage cache.', $deleted));

        return Command::SUCCESS;
    }
}
