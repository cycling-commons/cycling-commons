<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\SurfaceProfiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Standalone backfill of derived route surface profiles ({@see SurfaceProfiler}).
 *
 * @see docs/specs/route-domain.md §9
 *
 * @api
 */
#[AsCommand(name: 'app:catalog:route-surfaces', description: 'Recompute derived route surface profiles from the A-layer segments')]
final class RouteSurfacesCommand extends Command
{
    public function __construct(private readonly SurfaceProfiler $profiler)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->profiler->recomputeAll();
        $io->success(sprintf('Recomputed surface profiles for %d routes.', $count));

        return Command::SUCCESS;
    }
}
