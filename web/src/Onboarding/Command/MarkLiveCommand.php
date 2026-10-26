<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Coverage\BucketManifest;
use App\Coverage\CoverageManifest;
use App\Coverage\RoadPiecesManifest;
use App\Coverage\RoutesManifest;
use App\Coverage\SurfaceManifest;
use App\Onboarding\Countries;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The last onboarding step: a seeded country goes live, or the regions added to a live country do.
 * The cached tile manifests are dropped then: the repair published new tiles minutes ago, and the
 * regions show in the layer counts at once, so the map must not wait out the manifest TTL.
 *
 * @api
 */
#[AsCommand(name: 'app:country:mark-live', description: 'Mark a seeded country live, or the regions added to a live one')]
final class MarkLiveCommand extends Command
{
    public function __construct(
        private readonly Countries $countries,
        private readonly CoverageManifest $coverage,
        private readonly SurfaceManifest $surface,
        private readonly RoutesManifest $routes,
        private readonly RoadPiecesManifest $roadPieces,
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
        $io = new SymfonyStyle($input, $output);
        try {
            $cc = Countries::code((string) $input->getArgument('country'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $added = $this->countries->markAddedLive($cc);
        if ([] !== $added) {
            $this->forgetManifests($io);
            $io->success(sprintf('%s: %s live.', $cc, implode(', ', $added)));

            return Command::SUCCESS;
        }
        if (!$this->countries->markLive($cc)) {
            $status = $this->countries->status($cc);
            $io->error(sprintf('%s is %s; mark-live needs a seeded country, or a live one with added regions.', $cc, null === $status ? 'not planned' : $status->value));

            return Command::FAILURE;
        }
        $this->forgetManifests($io);
        $io->success(sprintf('%s is live.', $cc));

        return Command::SUCCESS;
    }

    /** A cache that cannot answer costs the map at most one manifest TTL, never the go-live. */
    private function forgetManifests(SymfonyStyle $io): void
    {
        $failed = array_filter(
            [$this->coverage, $this->surface, $this->routes, $this->roadPieces],
            static fn (BucketManifest $m): bool => !$m->forget(),
        );
        if ([] !== $failed) {
            $io->warning(sprintf('%d cached tile manifest(s) could not be dropped; the map shows the new tiles within the hour.', \count($failed)));
        }
    }
}
