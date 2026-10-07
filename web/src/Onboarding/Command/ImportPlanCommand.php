<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Onboarding\Countries;
use App\Onboarding\CountryBundle;
use App\Onboarding\CountryStatus;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads a bundle from app:country:export into a plan, so apply, repair, check and live run on staging's exact rows.
 *
 * @api
 */
#[AsCommand(name: 'app:country:import-plan', description: 'Read a country bundle (stdin or --file) into country + country_plan_region as a plan')]
final class ImportPlanCommand extends Command
{
    public function __construct(
        private readonly Countries $countries,
        private readonly CountryBundle $bundle,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'bundle file; - reads stdin', '-');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getOption('file');
        $raw = @file_get_contents('-' === $file ? 'php://stdin' : $file);
        if (false === $raw) {
            $io->error(sprintf('Nothing written: cannot read %s.', $file));

            return Command::FAILURE;
        }
        if ('' === trim($raw)) {
            $io->error('Nothing written: empty input (did the export fail?).');

            return Command::FAILURE;
        }
        try {
            $bundle = CountryBundle::validate(json_decode($raw, true, 512, \JSON_THROW_ON_ERROR));
        } catch (\JsonException|\InvalidArgumentException $e) {
            $io->error(sprintf('Nothing written: %s', $e->getMessage()));

            return Command::FAILURE;
        }
        $cc = $bundle['country']['code'];
        $status = $this->countries->status($cc);
        if (\in_array($status, [CountryStatus::Seeded, CountryStatus::Live], true)) {
            $io->error(sprintf('%s is %s here; its slugs are frozen identity. Nothing written.', $cc, $status->value));

            return Command::FAILURE;
        }
        try {
            $n = $this->bundle->importPlan($bundle);
        } catch (DBALException|\InvalidArgumentException $e) {
            $io->error(sprintf('Nothing written: %s', $e->getMessage()));

            return Command::FAILURE;
        }
        $io->success(sprintf('%s planned from the bundle: %d region row(s), extracts %s. Next: app:country:apply %s', $cc, $n, implode(',', $bundle['extracts']), $cc));

        return Command::SUCCESS;
    }
}
