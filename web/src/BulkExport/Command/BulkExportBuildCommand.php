<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport\Command;

use App\BulkExport\BulkExportBuilder;
use App\BulkExport\BulkExportBusy;
use App\BulkExport\BulkExportUnavailable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds and publishes one snapshot of the open catalogue, and deletes the
 * snapshots past the retention. Run weekly by a timer on the worker host
 * (docs/specs/operations.md §1); safe to run by hand at any time: a run that
 * starts while another is still building exits 1 and writes nothing.
 *
 * @see docs/specs/api-strategy.md §3.1
 *
 * @api
 */
#[AsCommand(name: 'app:export:build', description: 'Build and publish the bulk export of the open catalogue (GeoJSON, gzip, with a manifest)')]
final class BulkExportBuildCommand extends Command
{
    public function __construct(
        private readonly BulkExportBuilder $builder,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $manifest = $this->builder->build($this->clock->now());
        } catch (BulkExportUnavailable|BulkExportBusy $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['File', 'Features', 'Bytes', 'sha256'],
            array_map(static fn (array $f): array => [$f['name'], $f['features'], $f['bytes'], $f['sha256']], $manifest['files']),
        );
        $io->table(
            ['Source', 'Licence', 'Places', 'Routes'],
            array_map(static fn (array $s): array => [$s['key'], $s['licence_code'], $s['places'], $s['routes']], $manifest['sources']),
        );
        if ([] !== $manifest['left_out']) {
            $io->table(
                ['Left out', 'Licence', 'Why', 'Places', 'Routes'],
                array_map(static fn (array $s): array => [$s['key'], $s['licence_code'] ?? '-', $s['reason'], $s['places'], $s['routes']], $manifest['left_out']),
            );
        }
        $io->success(sprintf('Published snapshot %s (kept: the newest %d, and the first of every month).', $manifest['snapshot'], BulkExportBuilder::KEEP));

        return Command::SUCCESS;
    }
}
