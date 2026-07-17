<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Command\Coverage;

use App\Catalog\CoverageRetirement;
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
 * The touch-predicate is owned by CoverageRetirement — the SAME SQL fragment
 * CatalogProvider::itemRows()/curatedRefs() apply under COVERAGE_TILES=1, so
 * what the flag hides is structurally exactly what this deletes.
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
     * Retirement predicate over `item` (coverage-provider.md §9). The letter
     * guard is composed HERE, in the command itself, so A and B stay
     * structurally undeletable no matter how the shared touch-clause evolves;
     * the touch-clause comes from CoverageRetirement, the single owner all
     * provider call sites also consume.
     */
    private static function predicate(): string
    {
        return 'letter IN '.CoverageRetirement::lettersSqlTuple()
            .' AND '.CoverageRetirement::untouchedOsmSql('item');
    }

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
            'SELECT letter, COUNT(*) AS n FROM item WHERE '.self::predicate().' GROUP BY letter ORDER BY letter',
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
            $deleted = $this->db->executeStatement('DELETE FROM item WHERE '.self::predicate());
            $this->db->commit();
        } catch (DBALException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            // Non-DBAL failure mid-transaction: never leave it dangling —
            // roll back, then let the real error surface unchanged.
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        $io->success(sprintf('Deleted %d legacy OSM row(s) now served by the coverage cache.', $deleted));

        return Command::SUCCESS;
    }
}
