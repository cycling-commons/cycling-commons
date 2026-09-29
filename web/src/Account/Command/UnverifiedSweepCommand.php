<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\UnverifiedSweep;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily on the worker host (timer `purge-unverified`), beside the dormancy sweep.
 *
 * @see docs/specs/account-and-auth.md §6.7
 *
 * @api
 */
#[AsCommand(name: 'app:accounts:purge-unverified', description: 'Delete accounts not confirmed within 7 days')]
final class UnverifiedSweepCommand extends Command
{
    public function __construct(
        private readonly UnverifiedSweep $sweep,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete the accounts.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = !$input->getOption('force');

        $result = $this->sweep->run($this->clock->now(), $dryRun);

        if ($dryRun) {
            $io->note('Dry run: nothing deleted. Pass --force to act.');
        }
        $io->success(sprintf(
            '%d unconfirmed account(s) older than %d days %s deleted (%d considered).',
            $result['deleted'],
            UnverifiedSweep::DAYS,
            $dryRun ? 'would be' : 'were',
            $result['considered'],
        ));

        return Command::SUCCESS;
    }
}
