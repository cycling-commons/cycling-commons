<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\DormancyLadder;
use App\Account\DormancySweep;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Warn dormant accounts, then close the ones that never came back.
 *
 * Meant for a daily timer on the worker host, beside `app:moderation:gc`. Daily
 * rather than monthly so a notice lands close to the day it is earned, and so a
 * missed run costs a day instead of a month.
 *
 * **Run it with `--dry-run` first on any environment where it has not run
 * before.** The first real run on an old database sends every warning that has
 * been earned since the accounts were made, and there is no undo for the
 * deletions that follow a month later.
 *
 * @see docs/specs/account-and-auth.md §6.5
 *
 * @api
 */
#[AsCommand(name: 'app:accounts:dormancy', description: 'Warn accounts idle 12/22/23 months, delete at 24')]
final class DormancySweepCommand extends Command
{
    public function __construct(
        private readonly DormancySweep $sweep,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually send the notices and delete the accounts.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = !$input->getOption('force');

        $result = $this->sweep->run($this->clock->now(), $dryRun);

        $rows = [];
        foreach (DormancyLadder::NOTICES as $code => $months) {
            $rows[] = [$code, sprintf('%d months idle', $months), $result['notified'][$code]];
        }
        $rows[] = ['deleted', sprintf('%d months idle, warned', DormancyLadder::DELETE_AFTER_MONTHS), $result['deleted']];

        $io->table(['stage', 'when', $dryRun ? 'would be' : 'were'], $rows);

        if ($dryRun) {
            $io->note('Dry run: no mail sent, no account deleted. Pass --force to act.');
        }

        $io->success(sprintf('%d dormant account(s) considered.', $result['considered']));

        return Command::SUCCESS;
    }
}
