<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the boundaries the project holds into `world_division`.
 *
 * Reads what `tools/divisions/export_candidates.py` wrote: one file per
 * country, one GeoJSON Feature per line, every division of the curatable tier,
 * onboarded or not.
 *
 * Line by line, never the whole file. A large country is tens of megabytes of
 * coordinates, and decoding that as a single document inside a 128 MB PHP
 * process is the mistake the exporter itself made first (2026-09-14, when
 * loading every polygon at once filled the machine's RAM and swap).
 *
 * **This onboards nothing.** A row here is a boundary we hold, which is what
 * lets a request for it be acted on; `region` stays the list of places the
 * Commons actually runs, and promoting a row into it is a separate decision
 * (owner 2026-09-14: "we can prepare the db in the backend we just not onboard
 * them yet").
 *
 * Upserts on (country, subtype, ISO code or name), so a re-import after a new Overture
 * release refreshes geometry in place rather than growing a second copy of
 * every province. Nothing is deleted: a division that vanishes from a release
 * is far more likely to be a naming change than a country losing a province,
 * and a silent delete would take a `region` row's source out from under it.
 *
 * @see docs/specs/moderation-and-contribution.md §11.1a
 *
 * @api
 */
#[AsCommand(
    name: 'app:divisions:import',
    description: 'Load division boundaries from tools/divisions output into world_division',
)]
final class DivisionsImportCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Where the exporter wrote its files', 'var/divisions')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'One ISO 3166-1 alpha-2, default every file present')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be written and write nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = (string) $input->getOption('dir');
        $dry = true === $input->getOption('dry-run');

        $country = $input->getOption('country');
        $pattern = \is_string($country) && '' !== $country
            ? rtrim($dir, '/').'/'.strtolower($country).'.ndjson'
            : rtrim($dir, '/').'/*.ndjson';

        $files = glob($pattern) ?: [];
        if ([] === $files) {
            $io->error(sprintf('No files match %s. Run tools/divisions/export_candidates.py first.', $pattern));

            return Command::FAILURE;
        }

        $written = 0;
        $skipped = 0;
        foreach ($files as $file) {
            $cc = strtoupper(pathinfo($file, \PATHINFO_FILENAME));
            if (1 !== preg_match('/^[A-Z]{2}$/', $cc)) {
                ++$skipped;
                continue;
            }

            $handle = fopen($file, 'r');
            if (false === $handle) {
                ++$skipped;
                continue;
            }

            while (false !== ($line = fgets($handle))) {
                if ('' === trim($line)) {
                    continue;
                }
                /** @var mixed $feature */
                $feature = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($feature)) {
                    ++$skipped;
                    continue;
                }
                $props = \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                $name = $props['name'] ?? null;
                $geometry = $feature['geometry'] ?? null;
                if (!\is_string($name) || '' === trim($name) || !\is_array($geometry)) {
                    ++$skipped;
                    continue;
                }

                if (!$dry) {
                    $this->upsert(
                        $cc,
                        \is_string($props['iso'] ?? null) && '' !== $props['iso'] ? $props['iso'] : null,
                        trim($name),
                        \is_string($props['subtype'] ?? null) ? $props['subtype'] : 'region',
                        (string) json_encode($geometry, \JSON_THROW_ON_ERROR),
                        is_numeric($props['area_km2'] ?? null) ? (float) $props['area_km2'] : 0.0,
                        'overture:'.(\is_string($props['release'] ?? null) ? $props['release'] : 'unknown'),
                    );
                }
                ++$written;
            }
            fclose($handle);
        }

        $io->definitionList(
            ['files' => \count($files)],
            [$dry ? 'would write' : 'written' => $written],
            ['skipped' => $skipped],
        );

        if ($dry) {
            $io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $io->success('Boundaries loaded. Nothing is onboarded by this: `region` is unchanged.');

        return Command::SUCCESS;
    }

    private function upsert(string $cc, ?string $iso, string $name, string $subtype, string $geojson, float $area, string $source): void
    {
        // ST_MakeValid because a self-intersecting ring is a fact of published
        // boundary data, and one bad polygon must not stop a whole country.
        // ST_Multi so a country whose provinces are a mix of single and
        // multipart polygons still answers one type to anything reading it.
        $this->db->executeStatement(
            'INSERT INTO world_division (country_code, iso_code, name, subtype, geom, area_km2, source, created_at, updated_at)
             VALUES (:cc, :iso, :name, :subtype, ST_Multi(ST_MakeValid(ST_GeomFromGeoJSON(:geom))), :area, :source, NOW(), NOW())
             ON CONFLICT (country_code, subtype, (COALESCE(iso_code, name))) DO UPDATE
                SET name = EXCLUDED.name,
                    geom = EXCLUDED.geom,
                    area_km2 = EXCLUDED.area_km2,
                    source = EXCLUDED.source,
                    updated_at = NOW()',
            [
                'cc' => $cc,
                'iso' => $iso,
                'name' => $name,
                'subtype' => $subtype,
                'geom' => $geojson,
                'area' => $area,
                'source' => $source,
            ],
        );
    }
}
