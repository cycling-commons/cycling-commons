<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Onboarding\Countries;
use App\Onboarding\Restamper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-stamps submissions and items for a set of countries, after their regions changed.
 *
 * @api
 */
#[AsCommand(name: 'app:regions:restamp', description: 'Re-derive submission region/country and item country for the listed countries')]
final class RestampRegionsCommand extends Command
{
    public function __construct(private readonly Restamper $restamper)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('countries', null, InputOption::VALUE_REQUIRED, 'csv of ISO 3166-1 alpha-2 codes, e.g. DK,DE')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'count what would change, write nothing')
            ->addOption('recount', null, InputOption::VALUE_NONE, 'run app:coverage:recount afterwards (slow)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $countries = array_values(array_unique(array_map(
                Countries::code(...),
                array_filter(array_map(trim(...), explode(',', (string) $input->getOption('countries'))), static fn (string $c): bool => '' !== $c),
            )));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        if ([] === $countries) {
            $io->error('Pass --countries, e.g. --countries=DK,DE');

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $counts = $this->restamper->restamp($countries, $dryRun);
        $io->table(['table', 'country', 'rows'], self::rows($counts));
        $io->success(sprintf('%s for %s.', $dryRun ? 'Re-stamp dry run, nothing written,' : 'Re-stamped', implode(',', $countries)));

        if ($input->getOption('recount') && !$dryRun && null !== $this->getApplication()) {
            return $this->getApplication()->find('app:coverage:recount')->run(new ArrayInput([]), $output);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{submission: array<string, int>, item: array<string, int>} $counts
     *
     * @return list<list<string>>
     */
    public static function rows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $table => $byCountry) {
            if ([] === $byCountry) {
                $rows[] = [$table, '-', '0'];
            }
            foreach ($byCountry as $cc => $n) {
                $rows[] = [$table, $cc, (string) $n];
            }
        }

        return $rows;
    }
}
