<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic\Command;

use App\Traffic\TrafficPool;
use App\Traffic\TrafficStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Prints the traffic tables decrypted, for development only. Encryption is
 * never switched off; this is how a developer reads what is stored.
 *
 * @see docs/specs/traffic-measurements.md §4.8
 *
 * @api
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:traffic:dump', description: 'Print the traffic tables decrypted (development only)')]
final class TrafficDumpCommand extends Command
{
    public function __construct(
        private readonly TrafficStore $store,
        private readonly TrafficPool $pool,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('way', null, InputOption::VALUE_REQUIRED, 'Only this OSM way id');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $way = null !== $input->getOption('way') ? (int) $input->getOption('way') : null;

        $totals = new Table($output);
        $totals->setHeaders(['way', 'region', 'dir', 'label', 'band', 'day type', 'quarter', 'm', 's', 'cars', 'nearby', 'with speed', 'lines', 'day groups']);
        foreach ($this->store->totals() as $t) {
            if (null !== $way && $t['way'] !== $way) {
                continue;
            }
            $totals->addRow([$t['way'], $t['region'] ?? '-', $t['dir'], $t['label'], $t['band'], $t['dayType'], $t['quarter'],
                $t['distanceM'], $t['timeS'], $t['passes'], $t['nearby'], $t['speedPasses'], $t['lines'], substr_count(decbin($t['days']), '1')]);
        }
        $output->writeln('<info>traffic_total</info> (plain sums; days are groups, never dates)');
        $totals->render();

        $waiting = new Table($output);
        $waiting->setHeaders(['way', 'dir', 'label', 'band', 'day type', 'quarter', 'day group', 'm', 'cars', 'nearby']);
        foreach ($this->pool->waiting() as $l) {
            if (null !== $way && $l['way'] !== $way) {
                continue;
            }
            $waiting->addRow([$l['way'], $l['dir'], $l['label'], $l['band'], $l['dayType'], $l['quarter'], $l['dayGroup'], $l['distanceM'], $l['passes'], $l['nearby']]);
        }
        $output->writeln('<info>traffic_pool</info> (the waiting room, decrypted)');
        $waiting->render();

        return Command::SUCCESS;
    }
}
