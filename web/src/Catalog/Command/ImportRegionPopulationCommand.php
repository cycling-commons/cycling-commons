<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\RegionFacts;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import each region's population and the year it was counted, from
 * Wikidata (CC0), into `region.facts_harvest`
 * (moderation-and-contribution.md §3.1g). The artifact is
 * `tools/wikimedia/out/region-population.json`, written at build time by
 * `tools/wikimedia/region_population.py` and reviewed before it is
 * committed: `{slug: {population, year, qid}}`.
 *
 * Its own column, so the import never touches a figure a person set
 * (`region.facts`): the page shows a person's figure over the fetched one.
 * A malformed entry stops the import before anything is written.
 *
 * @api
 */
#[AsCommand(name: 'app:regions:import-population', description: 'Import build-time Wikidata population figures for region pages')]
final class ImportRegionPopulationCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('artifact', InputArgument::REQUIRED, 'Path to region-population.json');
        $this->addOption('prune', null, InputOption::VALUE_NONE, 'Drop the fetched population of regions not in the artifact');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('artifact');
        $raw = @file_get_contents($path);
        if (false === $raw) {
            $io->error(sprintf('Cannot read %s.', $path));

            return Command::FAILURE;
        }
        try {
            $artifact = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Not JSON: %s', $e->getMessage()));

            return Command::FAILURE;
        }
        if (!\is_array($artifact)) {
            $io->error('The artifact must be an object of {slug: {population, year, qid}}.');

            return Command::FAILURE;
        }

        // Every entry is checked before any is written.
        $thisYear = (int) $this->clock->now()->format('Y');
        foreach ($artifact as $slug => $entry) {
            if (!\is_array($entry)
                || !RegionFacts::isValid(RegionFacts::POPULATION, ['value' => $entry['population'] ?? null, 'year' => $entry['year'] ?? null])
                || (int) $entry['year'] > $thisYear
                || !\is_string($entry['qid'] ?? null) || 1 !== preg_match('~^Q\d{1,12}$~', $entry['qid'])) {
                $io->error(sprintf('%s: every entry needs a whole population, a year from %d to %d and a Wikidata id (Q...).', (string) $slug, RegionFacts::YEAR_MIN, $thisYear));

                return Command::FAILURE;
            }
        }

        /** @var array<string, int> $bySlug */
        $bySlug = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, slug FROM region') as $row) {
            $bySlug[(string) $row['slug']] = (int) $row['id'];
        }

        $written = 0;
        $unknown = [];
        foreach ($artifact as $slug => $entry) {
            $slug = (string) $slug;
            if (!isset($bySlug[$slug])) {
                $unknown[] = $slug;
                continue;
            }
            $population = ['value' => (int) $entry['population'], 'year' => (int) $entry['year'], 'qid' => (string) $entry['qid']];
            // jsonb_set keeps any other fetched figure the column holds.
            $this->db->executeStatement(
                "UPDATE region SET facts_harvest = jsonb_set(COALESCE(facts_harvest, '{}'::jsonb), '{population}', CAST(:p AS jsonb)), updated_at = NOW() WHERE id = :id",
                ['p' => json_encode($population, \JSON_THROW_ON_ERROR), 'id' => $bySlug[$slug]],
            );
            ++$written;
        }

        $pruned = 0;
        if ((bool) $input->getOption('prune')) {
            $keep = array_values(array_intersect(array_keys($bySlug), array_map(strval(...), array_keys($artifact))));
            $sql = "UPDATE region SET facts_harvest = NULLIF(facts_harvest - 'population', '{}'::jsonb), updated_at = NOW() WHERE facts_harvest->'population' IS NOT NULL";
            $pruned = [] === $keep
                ? (int) $this->db->executeStatement($sql)
                : (int) $this->db->executeStatement($sql.' AND slug NOT IN (:keep)', ['keep' => $keep], ['keep' => ArrayParameterType::STRING]);
        }

        if ([] !== $unknown) {
            $io->warning(sprintf('%d slug(s) in the artifact match no region and were skipped: %s', \count($unknown), implode(', ', $unknown)));
        }
        $io->success(sprintf('Population written for %d region(s)%s.', $written, $pruned > 0 ? sprintf(', dropped on %d', $pruned) : ''));

        return Command::SUCCESS;
    }
}
