<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Provider\Command;

use App\Provider\Entity\DataProvider;
use App\Provider\ProviderHarvest;
use App\Provider\ProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ingest one provider's normalised records into the catalogue.
 *
 * The file this reads is Python's output ({@see pipeline/providers}), which
 * has already fetched the service, reprojected to WGS84 and applied the
 * provider's field map. The split follows the boundary this project already
 * draws: reading a geospatial service is Python's, matching rows against rows
 * is this side's.
 *
 * Dry by default. A harvest inserts into the catalogue riders read, so seeing
 * the counts before anything is written is the normal way to run it, and
 * `--write` is the deliberate second step.
 *
 * @see docs/specs/data-provider-hierarchy.md §5
 *
 * @api
 */
#[AsCommand(name: 'app:providers:harvest', description: 'Ingest one provider\'s normalised records into the catalogue')]
final class HarvestProviderCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderHarvest $harvest,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::REQUIRED, 'Provider key, e.g. rivm-drinkwater');
        $this->addArgument('file', InputArgument::REQUIRED, 'Normalised GeoJSON produced by pipeline/providers');
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Actually write (default is a dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key = (string) $input->getArgument('provider');
        $provider = $this->providerByKey($key);
        if (null === $provider) {
            $io->error(sprintf('No provider "%s" in the registry.', $key));

            return Command::FAILURE;
        }
        if (!$provider->isEnabled()) {
            // Disabled means "keep the rows, stop refreshing", so refreshing
            // one is the one thing this must not do.
            $io->error(sprintf('Provider "%s" is paused. Enable it on the desk first.', $key));

            return Command::FAILURE;
        }

        try {
            $features = $this->readFeatures((string) $input->getArgument('file'), $provider);
        } catch (\JsonException|\RuntimeException $e) {
            $io->error($e->getMessage());
            $this->recordFailure($provider, $e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $features) {
            // Not an empty publisher: an empty file is a fetch that failed
            // quietly, and treating it as data would mark every row stale.
            $io->error('The file carries no features. Refusing to treat an empty fetch as an empty publisher.');
            $this->recordFailure($provider, 'empty fetch');

            return Command::FAILURE;
        }

        $write = (bool) $input->getOption('write');
        $this->em->getConnection()->beginTransaction();
        try {
            $counts = $this->harvest->apply($provider, $features);
            if ($write) {
                $this->em->getConnection()->commit();
                $provider->recordRun(new \DateTimeImmutable(), \count($features), null);
                $this->em->flush();
                $this->harvest->invalidateCitations();
            } else {
                $this->em->getConnection()->rollBack();
            }
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            $io->error($e->getMessage());
            $this->recordFailure($provider, $e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['read', 'inserted', 'attached to OSM', 'updated', 'left to riders', 'stale upstream'],
            [[\count($features), $counts['inserted'], $counts['attached'], $counts['updated'], $counts['skipped_rider'], $counts['stale']]],
        );

        if (!$write) {
            $io->warning('Dry run. Nothing was written; re-run with --write.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%s: %d records applied.', $key, \count($features)));

        return Command::SUCCESS;
    }

    private function providerByKey(string $key): ?DataProvider
    {
        foreach ($this->registry->all() as $provider) {
            if ($provider->getKey() === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Reads the normalised file, refusing anything it cannot vouch for.
     *
     * Every refusal here is a fetch that went wrong upstream, and a harvest
     * that guessed past one would write the guess into the catalogue.
     *
     * @return list<array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null}>
     *
     * @throws \JsonException
     * @throws \RuntimeException
     */
    private function readFeatures(string $path, DataProvider $provider): array
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException(sprintf('Cannot read "%s".', $path));
        }

        /** @var array{features?: list<array<string, mixed>>} $doc */
        $doc = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        $letters = $provider->getLetters();

        $out = [];
        foreach ($doc['features'] ?? [] as $i => $feature) {
            /** @var array{properties?: array<string, mixed>, geometry?: array{type?: string, coordinates?: array{0: float, 1: float}}} $feature */
            $props = $feature['properties'] ?? [];
            $geom = $feature['geometry'] ?? [];

            $ref = \is_string($props['ref'] ?? null) ? $props['ref'] : '';
            $letter = \is_string($props['letter'] ?? null) ? $props['letter'] : '';
            if ('' === $ref || '' === $letter) {
                throw new \RuntimeException(sprintf('Feature %d has no ref or no letter.', $i));
            }
            if ([] !== $letters && !\in_array($letter, $letters, true)) {
                // The registry says which letters this dataset fills. A
                // feature outside them is a field map pointed at the wrong
                // layer, which is how one click floods a catalogue.
                throw new \RuntimeException(sprintf('Feature %d is letter "%s", which %s does not fill.', $i, $letter, $provider->getKey()));
            }
            if ('Point' !== ($geom['type'] ?? null) || !isset($geom['coordinates'][0], $geom['coordinates'][1])) {
                throw new \RuntimeException(sprintf('Feature %d is not a WGS84 point.', $i));
            }

            $lng = (float) $geom['coordinates'][0];
            $lat = (float) $geom['coordinates'][1];
            if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
                // Almost always an unreprojected coordinate: EPSG:28992 metres
                // read as degrees land in the hundreds of thousands.
                throw new \RuntimeException(sprintf('Feature %d is outside WGS84 bounds; was it reprojected?', $i));
            }

            $attributes = $props;
            unset($attributes['ref'], $attributes['letter'], $attributes['name'], $attributes['country_code']);

            $out[] = [
                'ref' => $ref,
                'letter' => $letter,
                'name' => \is_string($props['name'] ?? null) ? $props['name'] : '',
                'lat' => $lat,
                'lng' => $lng,
                'attributes' => $attributes,
                'country_code' => \is_string($props['country_code'] ?? null) ? $props['country_code'] : null,
            ];
        }

        return $out;
    }

    private function recordFailure(DataProvider $provider, string $reason): void
    {
        $provider->recordRun(new \DateTimeImmutable(), null, $reason);
        $this->em->flush();
        // The desk shows this, and the desk is where somebody notices.
        $this->logger->error('Provider harvest failed.', ['provider' => $provider->getKey(), 'reason' => $reason]);
    }
}
