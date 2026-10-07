<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Catalog\RegionDerivations;
use App\Catalog\RegionLabels;
use App\Catalog\RegionUpserter;
use App\Onboarding\Countries;
use App\Onboarding\CountryStatus;
use App\Onboarding\Restamper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns a confirmed plan into region rows, derivations and re-stamped rider data, in one transaction.
 *
 * @api
 */
#[AsCommand(name: 'app:country:apply', description: 'Turn a planned country into region rows, derivations and re-stamped rider data')]
final class ApplyCountryCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly Countries $countries,
        private readonly RegionUpserter $regions,
        private readonly RegionDerivations $derivations,
        private readonly Restamper $restamper,
        private readonly RegionLabels $labels,
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
        $status = $this->countries->status($cc);
        if (!\in_array($status, [CountryStatus::Planned, CountryStatus::Seeded], true)) {
            $io->error(sprintf('%s is %s; apply needs a planned country (or seeded, for a rerun).', $cc, null === $status ? 'not planned' : $status->value));

            return Command::FAILURE;
        }
        $plan = $this->countries->planRegions($cc);
        if ([] === $plan) {
            $io->error(sprintf('%s has no plan rows; run python -m onboarding.plan %s first.', $cc, $cc));

            return Command::FAILURE;
        }
        if ([] === $this->countries->extracts($cc)) {
            $io->error(sprintf('%s has no country_extract row; the plan is incomplete.', $cc));

            return Command::FAILURE;
        }
        $taken = $this->countries->foreignSlugs($cc, array_column($plan, 'slug'));
        if ([] !== $taken) {
            $io->error(sprintf('Plan slug(s) already belong to another country: %s. Nothing was written.', implode(', ', $taken)));

            return Command::FAILURE;
        }

        try {
            $this->db->beginTransaction();
            // Apply waits behind a harvest or an import at most this long, then gives up without writing.
            $this->db->executeStatement("SET LOCAL lock_timeout = '10s'");
            foreach ($plan as $row) {
                $this->regions->upsert([
                    'slug' => $row['slug'], 'name' => $row['name'], 'area_km2' => $row['area_km2'],
                    'country_code' => $cc, 'iso_code' => $row['iso_code'], 'admin_level' => $row['admin_level'],
                    'source' => 'overture',
                ], $row['geojson'], sprintf('plan %s/%s', $cc, $row['slug']), $row['labels']);
            }
            $this->regions->assertTessellates();
            $this->derivations->shapes();
            $neighbours = $this->countries->neighbours($cc);
            $derived = $this->derivations->dependentsNear([$cc, ...$neighbours], Countries::NEIGHBOUR_DEG);
            $stamped = $this->restamper->restamp([$cc, ...$neighbours], false);
            $this->countries->markSeeded($cc);
            $this->countries->fillTimezones($cc);
            $this->db->commit();
        } catch (\InvalidArgumentException|\JsonException|DBALException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            // 55P03 lock_not_available: DBAL does not map it to LockWaitTimeoutException on PostgreSQL.
            $io->error($e instanceof DriverException && '55P03' === $e->getSQLState()
                ? sprintf('%s: waited 10 s for a lock another writer holds (a harvest or an import); nothing was written. Rerun apply when it is done.', $cc)
                : $e->getMessage());

            return Command::FAILURE;
        }
        $this->labels->reset();

        $io->text(sprintf('%s: %d region row(s); neighbours: %s', $cc, \count($plan), [] === $neighbours ? 'none' : implode(',', $neighbours)));
        $io->text(sprintf('rows re-derived near %s: membership %d, rider bases %d, route surfaces %d', implode(',', [$cc, ...$neighbours]), $derived['assigned'], $derived['rederived'], $derived['surfaced']));
        $io->table(['table', 'country', 'rows'], RestampRegionsCommand::rows($stamped));
        $io->success(sprintf('%s is seeded. Next: python -m onboarding.repair %s', $cc, $cc));

        return Command::SUCCESS;
    }
}
