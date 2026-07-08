<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\SurfaceProfiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recomputes every recommended_route's derived surface profile
 * ({@see SurfaceProfiler}) from the served A-layer segments. The importer runs
 * this automatically after a harvest; this command is the standalone ops/dev
 * backfill for when the A-layer changed without a route re-import.
 *
 * @api Console entry point (dev/ops backfill, safe to re-run).
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
