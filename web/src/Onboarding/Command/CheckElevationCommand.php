<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Onboarding\Countries;
use App\Onboarding\CountryStatus;
use App\Onboarding\ElevationProbe;
use App\Onboarding\ElevationSample;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asks the elevation instance for 25 points of a country and fails when it has no tiles there.
 *
 * Valhalla answers null for a missing tile and 0 when no elevation is loaded,
 * both silently; a climb would then publish flat or without a profile.
 *
 * @api
 */
#[AsCommand(name: 'app:country:check-elevation', description: 'Fail when the elevation instance has no tiles for a country')]
final class CheckElevationCommand extends Command
{
    private const float MAX_BAD_SHARE = 0.8;

    public function __construct(
        private readonly Countries $countries,
        private readonly ElevationSample $sample,
        private readonly ElevationProbe $probe,
    ) {
        parent::__construct();
    }

    /** @param list<float|null> $heights */
    public static function failed(array $heights): bool
    {
        return [] === $heights || self::badCount($heights) > self::MAX_BAD_SHARE * \count($heights);
    }

    /** @param list<float|null> $heights */
    private static function badCount(array $heights): int
    {
        return \count(array_filter($heights, static fn (?float $h): bool => null === $h || 0.0 === $h));
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
        if (!\in_array($this->countries->status($cc), [CountryStatus::Seeded, CountryStatus::Live], true)) {
            $io->error(sprintf('%s has no region rows yet; check-elevation needs a seeded or live country.', $cc));

            return Command::FAILURE;
        }
        $points = $this->sample->points($cc);
        if ([] === $points) {
            $io->error(sprintf('%s has no region geometry to sample.', $cc));

            return Command::FAILURE;
        }

        $rows = [];
        $heights = [];
        foreach ($points as $p) {
            $endpoint = $this->probe->endpointFor($p['lat'], $p['lon']);
            if ('' === $endpoint) {
                $io->error(sprintf('There is no elevation endpoint for %.4f, %.4f: ELEVATION_URL is unset.', $p['lat'], $p['lon']));

                return Command::FAILURE;
            }
            $h = $this->probe->height($endpoint, $p['lat'], $p['lon']);
            $heights[] = $h;
            $rows[] = [sprintf('%.4f', $p['lat']), sprintf('%.4f', $p['lon']), $endpoint, null === $h ? 'null' : sprintf('%.1f', $h)];
        }
        $io->table(['lat', 'lon', 'endpoint', 'height'], $rows);

        $bad = self::badCount($heights);
        if (self::failed($heights)) {
            $io->error(sprintf('%d of %d answers are null or 0: install DEM tiles for %s (wiki: Building elevation tiles), restart the instance, check ELEVATION_URLS.', $bad, \count($heights), $cc));

            return Command::FAILURE;
        }
        $io->success(sprintf('%s: %d of %d answers carry a height.', $cc, \count($heights) - $bad, \count($heights)));

        return Command::SUCCESS;
    }
}
