<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Onboarding\Countries;
use App\Onboarding\CountryBundle;
use App\Onboarding\CountryStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes a live country's rows to stdout as a bundle for app:country:import-plan on another database.
 *
 * @api
 */
#[AsCommand(name: 'app:country:export', description: 'Write a live country (country, extracts, regions) to stdout as JSON')]
final class ExportCountryCommand extends Command
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
        $this->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2, e.g. DK');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $cc = Countries::code((string) $input->getArgument('country'));
        } catch (\InvalidArgumentException $e) {
            $err->writeln($e->getMessage());

            return Command::INVALID;
        }
        if (CountryStatus::Live !== $this->countries->status($cc)) {
            $err->writeln(sprintf('%s is not live; only a live country is exported.', $cc));

            return Command::FAILURE;
        }
        $output->write(json_encode($this->bundle->export($cc), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
