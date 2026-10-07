<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Onboarding\Countries;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One line per onboarded country: status, timestamps, region count, extracts.
 *
 * @api
 */
#[AsCommand(name: 'app:country:status', description: 'Show the onboarding state of one country or all of them')]
final class CountryStatusCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('country', InputArgument::OPTIONAL, 'ISO 3166-1 alpha-2, e.g. DK; all countries when left out');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $raw = $input->getArgument('country');
        $params = [];
        $where = '';
        if (\is_string($raw) && '' !== $raw) {
            try {
                $params['cc'] = Countries::code($raw);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }
            $where = 'WHERE c.code = :cc';
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.code, c.status,
                    to_char(c.planned_at, 'YYYY-MM-DD HH24:MI') AS planned,
                    to_char(c.seeded_at, 'YYYY-MM-DD HH24:MI') AS seeded,
                    to_char(c.live_at, 'YYYY-MM-DD HH24:MI') AS live,
                    (SELECT COUNT(*) FROM region r WHERE r.country_code = c.code) AS regions,
                    COALESCE((SELECT string_agg(e.slug, ',' ORDER BY e.slug) FROM country_extract e WHERE e.country_code = c.code), '') AS extracts
               FROM country c {$where} ORDER BY c.code",
            $params,
        );
        if ([] === $rows) {
            $io->error(sprintf('No country row for %s.', $params['cc'] ?? 'any country'));

            return Command::FAILURE;
        }
        $io->table(
            ['country', 'status', 'planned', 'seeded', 'live', 'regions', 'extracts'],
            array_map(static fn (array $r): array => [
                trim((string) $r['code']), (string) $r['status'], (string) ($r['planned'] ?? '-'),
                (string) ($r['seeded'] ?? '-'), (string) ($r['live'] ?? '-'), (string) $r['regions'], (string) $r['extracts'],
            ], $rows),
        );

        return Command::SUCCESS;
    }
}
