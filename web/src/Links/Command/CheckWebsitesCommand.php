<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Links\Command;

use App\Links\WebsiteSweep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `app:websites:check` (docs/specs/website-liveness.md section 2): the sweep
 * over the website hosts that are due, on the worker, daily within a limit
 * and after each coverage harvest.
 *
 * @api
 */
#[AsCommand(name: 'app:websites:check', description: 'Check the websites behind coverage points and items: live, parked, dead or unknown')]
final class CheckWebsitesCommand extends Command
{
    public function __construct(private readonly WebsiteSweep $sweep)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many hosts this run checks at most', '5000');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $counts = $this->sweep->run($limit);
        $io->writeln(sprintf(
            '%d hosts checked: %d live, %d parked, %d dead, %d unknown',
            $counts['hosts'], $counts['live'], $counts['parked'], $counts['dead'], $counts['unknown'],
        ));

        return Command::SUCCESS;
    }
}
