<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\DuplicateGuard;
use App\Catalog\Import\NameKey;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finds served rows that describe the same place and retires all but the best.
 *
 * `DuplicateGuard` stops NEW duplicates at import. This clears the ones already
 * in the database, and it is the reason the guard never has to write: retiring
 * a row is a curator decision, never automatic (catalog-data-model.md §4). A
 * curator running this command with `--write`, after reading the dry run it
 * prints by default, IS that decision. An import running unattended at 4am is
 * not.
 *
 * Two rows are the same place when they share a letter, reduce to the same
 * {@see NameKey}, and sit within {@see DuplicateGuard::RADIUS_M}. The keeper is
 * the highest {@see ItemSource::dedupeRank()}; the rest are retired, never
 * deleted, so their history and their ids survive.
 *
 * **A row a human has edited is never retired.** If curator edits land on a row
 * that would otherwise lose, the whole group is reported and left alone: the
 * edit is work this command cannot weigh, and the same reasoning already
 * shields edited rows from being overwritten by a re-import
 * (catalog-data-model.md §3).
 *
 * @see docs/specs/catalog-data-model.md §5
 *
 * @api
 */
#[AsCommand(
    name: 'app:catalog:dedupe',
    description: 'Retire served rows that duplicate a better-sourced row for the same place (dry run by default)',
)]
final class DedupePlacesCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually retire the losers (default is a dry run)')
            ->addOption('letter', null, InputOption::VALUE_REQUIRED, 'Only this catalog letter')
            ->addOption('radius', null, InputOption::VALUE_REQUIRED,
                'How close two rows must be to be one place, in metres', (string) DuplicateGuard::RADIUS_M);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('write');
        $radius = max(1, (int) $input->getOption('radius'));
        $letter = $input->getOption('letter');

        $rows = $this->servedRows(\is_string($letter) ? $letter : null);
        $io->writeln(sprintf('%d served row(s) with a name and a geometry', \count($rows)));

        /** @var array<string, list<array<string, mixed>>> $byKey */
        $byKey = [];
        foreach ($rows as $row) {
            $key = NameKey::of((string) $row['name']);
            if ('' === $key) {
                continue;
            }
            $byKey[$row['letter'].'|'.$key][] = $row;
        }

        $retire = [];
        $blocked = [];
        $groups = 0;
        foreach ($byKey as $label => $members) {
            if (\count($members) < 2) {
                continue;
            }
            foreach ($this->clusters($members, $radius) as $cluster) {
                if (\count($cluster) < 2) {
                    continue;
                }
                ++$groups;

                // Best rank wins; a tie goes to the oldest row, which is the one
                // other things are most likely to already point at.
                usort($cluster, static fn (array $a, array $b): int => [self::rank($b), (int) $a['id']] <=> [self::rank($a), (int) $b['id']]);
                $keeper = array_shift($cluster);

                $edited = array_filter($cluster, static fn (array $r): bool => (bool) $r['edited']);
                $skip = [] !== $edited;

                // Print the verdict per ROW, not just the group. A dry run whose
                // reader cannot tell which row is about to disappear is not a
                // dry run, it is a list.
                $this->report($io, $label, $keeper, $cluster, $skip);

                if ($skip) {
                    $blocked[] = sprintf('%s — #%s carries curator edits and will not be retired automatically',
                        $label, implode(', #', array_map(static fn (array $r): string => (string) $r['id'], $edited)));
                    continue;
                }

                foreach ($cluster as $loser) {
                    $retire[(int) $loser['id']] = (int) $keeper['id'];
                }
            }
        }

        if ([] === $retire && [] === $blocked) {
            $io->success(sprintf('No duplicate places within %d m.', $radius));

            return Command::SUCCESS;
        }

        if ([] !== $blocked) {
            $io->note("Left alone, needs a human:\n  ".implode("\n  ", $blocked));
        }

        if ([] !== $retire && $write) {
            $this->db->executeStatement(
                'UPDATE item SET state = :retired, updated_at = NOW() WHERE id IN (:ids)',
                ['retired' => ItemState::Retired->value, 'ids' => array_keys($retire)],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        }

        $io->{$write ? 'success' : 'warning'}(sprintf(
            '%d group(s); %d row(s) %s.',
            $groups,
            \count($retire),
            $write ? 'retired' : 'would be retired — re-run with --write',
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed>       $keeper
     * @param list<array<string, mixed>> $losers
     */
    private function report(SymfonyStyle $io, string $label, array $keeper, array $losers, bool $blocked): void
    {
        $io->writeln('');
        $io->writeln(sprintf('  <info>%s</info>', $label));
        $io->writeln($this->row('KEEP   ', $keeper));
        foreach ($losers as $row) {
            $io->writeln($this->row($blocked ? 'LEAVE  ' : 'RETIRE ', $row));
        }
    }

    /** @param array<string, mixed> $row */
    private function row(string $verdict, array $row): string
    {
        return sprintf('    %s #%-6s %-9s %-11s %s%s',
            $verdict, $row['id'], $row['source'], $row['state'], $row['name'],
            (bool) $row['edited'] ? ' [curator-edited]' : '');
    }

    /** @param array<string, mixed> $row */
    private static function rank(array $row): int
    {
        return ItemSource::tryFrom((string) $row['source'])?->dedupeRank() ?? 0;
    }

    /**
     * Single-linkage clusters within `radius` metres, on true geometry
     * distance so a climb's LINE is measured as a line, not as its midpoint.
     *
     * @param list<array<string, mixed>> $members
     *
     * @return list<list<array<string, mixed>>>
     */
    private function clusters(array $members, int $radius): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $members);
        /** @var list<array{a: int, b: int}> $near */
        $near = $this->db->fetchAllAssociative(
            'SELECT a.id AS a, b.id AS b
               FROM item a JOIN item b ON b.id > a.id
              WHERE a.id IN (:ids) AND b.id IN (:ids)
                AND ST_DWithin(a.geom::geography, b.geom::geography, :radius)',
            ['ids' => $ids, 'radius' => $radius],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        $parent = array_combine($ids, $ids);
        // Iterative, not recursive, with path halving: a long chain of merges
        // would otherwise recurse once per link.
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $x = $parent[$x] = $parent[$parent[$x]];
            }

            return $x;
        };
        foreach ($near as $pair) {
            $ra = $find((int) $pair['a']);
            $rb = $find((int) $pair['b']);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        }

        $out = [];
        foreach ($members as $row) {
            $out[$find((int) $row['id'])][] = $row;
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> */
    private function servedRows(?string $letter): array
    {
        $sql = 'SELECT i.id, i.letter, i.name, i.source, i.state,
                       EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = i.id) AS edited
                  FROM item i
                 WHERE i.state IN '.ItemState::servedSqlTuple().'
                   AND i.geom IS NOT NULL AND i.name IS NOT NULL AND i.name <> \'\'';
        $params = [];
        if (null !== $letter) {
            $sql .= ' AND i.letter = :letter';
            $params['letter'] = $letter;
        }

        $rows = $this->db->fetchAllAssociative($sql.' ORDER BY i.letter, i.name, i.id', $params);

        return $rows;
    }
}
