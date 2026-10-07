<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic\Command;

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
    public function __construct(private readonly TrafficStore $store)
    {
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

        $cells = new Table($output);
        $cells->setHeaders(['way', 'region', 'dir', 'label', 'slot', 'day type', 'season', 'quarter', 'm', 's', 'cars', 'nearby', 'with speed', 'sends']);
        foreach ($this->store->cells() as $c) {
            if (null !== $way && $c['way'] !== $way) {
                continue;
            }
            $cells->addRow([$c['way'], $c['region'] ?? '-', $c['dir'], $c['label'], $c['slot'], $c['dayType'], $c['season'], $c['quarter'],
                $c['distanceM'], $c['timeS'], $c['passes'], $c['nearby'] ?? 0, $c['speedPasses'], $c['contributions']]);
        }
        $output->writeln('<info>traffic_cell</info>');
        $cells->render();

        $riders = new Table($output);
        $riders->setHeaders(['row', 'way', 'buckets', 'm', 'days']);
        foreach ($this->store->riders() as $i => $r) {
            if (null !== $way && $r['way'] !== $way) {
                continue;
            }
            $days = array_unique(array_merge(...array_column($r['buckets'], 'days') ?: [[]]));
            $riders->addRow([$i + 1, $r['way'], \count($r['buckets']), array_sum(array_column($r['buckets'], 'd')), \count($days)]);
        }
        $output->writeln('<info>traffic_rider</info> (one row per rider per road; rows are not linked to accounts)');
        $riders->render();

        return Command::SUCCESS;
    }
}
