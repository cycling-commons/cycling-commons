<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\OsmLinker;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Links non-OSM catalog rows to the OSM object they record.
 *
 * The backfill half of "every source links back to OSM" (owner decision
 * 2026-08-24). Imports link as they write from now on; this is for the rows
 * that already exist, including rows from a source that may never be
 * re-harvested.
 *
 * Refs come from `coverage_poi`, the OSM mirror the pipeline already builds.
 * Nothing is fetched.
 *
 * Three outcomes per row, and only one of them writes:
 *
 * - **linked** — inside {@see OsmLinker::TIGHT_M} with an identical name key.
 * - **for review** — {@see OsmLinker::TIGHT_M}–{@see OsmLinker::LOOSE_M}. Left
 *   alone here; `app:catalog:findings` puts it on the curator data desk.
 * - **taken** — the OSM object is already claimed by another served row. That
 *   is a duplicate wearing a link, so it goes to the desk as one rather than
 *   being welded together here.
 *
 * @see docs/specs/catalog-data-model.md §5b
 *
 * @api
 */
#[AsCommand(
    name: 'app:catalog:link-osm',
    description: 'Link non-OSM rows to their OSM object via the coverage mirror (dry run by default)',
)]
final class LinkOsmCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly OsmLinker $linker,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually write the confident links (default is a dry run)')
            ->addOption('letter', null, InputOption::VALUE_REQUIRED, 'Only this catalog letter')
            ->addOption('source', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these sources')
            ->addOption('relink', null, InputOption::VALUE_NONE, 'Also reconsider rows that already carry an osm_ref');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('write');

        $rows = $this->candidates(
            \is_string($input->getOption('letter')) ? $input->getOption('letter') : null,
            $input->getOption('source'),
            (bool) $input->getOption('relink'),
        );
        $io->writeln(sprintf('%d row(s) to consider', \count($rows)));

        $linked = [];
        $review = [];
        $taken = [];
        foreach ($rows as $row) {
            $found = $this->linker->candidateFor(
                (string) $row['letter'], (string) $row['name'],
                (float) $row['lat'], (float) $row['lng'],
            );
            if (null === $found) {
                continue;
            }

            $line = sprintf('#%-6s %s %-9s %-34s -> %-22s %d m',
                $row['id'], $row['letter'], $row['source'],
                mb_substr((string) $row['name'], 0, 34), $found['ref'], (int) round($found['distanceM']));

            if ($this->linker->refIsTaken($found['ref'], (string) $row['letter'], (int) $row['id'])) {
                $taken[] = $line;
                continue;
            }
            if (!$found['confident']) {
                $review[] = $line;
                continue;
            }

            $linked[] = $line;
            if ($write) {
                // The link is an ANSWER, not just a ref: without the stamp
                // the approval gate and the desk would keep asking about a row
                // the machine already settled (catalog-data-model.md §5b).
                $this->db->executeStatement(
                    'UPDATE item SET osm_ref = :ref, osm_checked_at = NOW(), updated_at = NOW() WHERE id = :id',
                    ['ref' => $found['ref'], 'id' => $row['id']],
                );
            }
        }

        $this->section($io, $write ? 'Linked' : 'Would link', $linked);
        $this->section($io, sprintf('For the data desk (%d-%d m, never written here)', OsmLinker::TIGHT_M, OsmLinker::LOOSE_M), $review);
        $this->section($io, 'OSM object already claimed by another row — a duplicate, not a link', $taken);

        if ([] !== $review || [] !== $taken) {
            $io->note('Run `app:catalog:findings` to put those on the curator data desk.');
        }

        $io->{$write ? 'success' : 'warning'}(sprintf(
            '%d link(s) %s; %d for review; %d already claimed.',
            \count($linked),
            $write ? 'written' : 'would be written — re-run with --write',
            \count($review),
            \count($taken),
        ));

        return Command::SUCCESS;
    }

    /** @param list<string> $lines */
    private function section(SymfonyStyle $io, string $title, array $lines): void
    {
        if ([] === $lines) {
            return;
        }
        $io->writeln('');
        $io->writeln(sprintf('  <info>%s</info> (%d)', $title, \count($lines)));
        foreach ($lines as $line) {
            $io->writeln('    '.$line);
        }
    }

    /**
     * @param list<string> $sources
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(?string $letter, array $sources, bool $relink): array
    {
        // `source = 'osm'` rows are excluded because their source_ref IS the
        // osm ref; and `auto` rows are pipeline-derived with no real-world
        // counterpart to link.
        $sql = "SELECT i.id, i.letter, i.source, i.name,
                       ST_Y(ST_Centroid(i.geom)) AS lat, ST_X(ST_Centroid(i.geom)) AS lng
                  FROM item i
                 WHERE i.source NOT IN ('osm', 'auto')
                   AND i.state IN ".ItemState::servedSqlTuple()."
                   AND i.name IS NOT NULL AND i.name <> ''
                   AND i.geom IS NOT NULL";
        $params = [];
        if (!$relink) {
            // osm_checked_at, not osm_ref: a curator's "not in OSM" leaves the
            // ref NULL on purpose, and reconsidering it here would re-ask a
            // question a human already answered (catalog-data-model.md §5b).
            $sql .= ' AND i.osm_checked_at IS NULL';
        }
        if (null !== $letter) {
            $sql .= ' AND i.letter = :letter';
            $params['letter'] = $letter;
        }
        if ([] !== $sources) {
            $sql .= ' AND i.source IN (:sources)';
            $params['sources'] = $sources;
        }

        $rows = $this->db->fetchAllAssociative(
            $sql.' ORDER BY i.letter, i.name, i.id',
            $params,
            [] !== $sources ? ['sources' => \Doctrine\DBAL\ArrayParameterType::STRING] : [],
        );

        return $rows;
    }
}
