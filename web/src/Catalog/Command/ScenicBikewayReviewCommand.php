<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A scenic item stays only within the P rule's range of a bike way.
 *
 * The coverage load applies that rule to OSM points; catalog items never pass
 * through it. `--export` lists the catalog's scenic items, the pipeline measures
 * them (`python -m coverage.scenic_review`), and `--apply` retires the ones out
 * of range that nobody has touched. An item a person touched (verified, edited,
 * confirmed, marked best-of, or added by a rider or by hand) is listed for a
 * person instead: their act is a decision the measurement did not see. An item
 * no extract covers is left alone, because missing data is not a verdict.
 *
 * @see docs/specs/scenic-views.md
 *
 * @api
 */
#[AsCommand(name: 'app:scenic:bikeway-review', description: 'Retire catalog scenic views that are not along a bike way')]
final class ScenicBikewayReviewCommand extends Command
{
    /** Sources a machine brought in; any other source is a person's addition. */
    private const array HARVESTED = ['wikidata', 'osm'];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Write the scenic items to measure to this JSON file')
            ->addOption('apply', null, InputOption::VALUE_REQUIRED, 'Read the measurement from this JSON file and retire what fails')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --apply: report, retire nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $export = $input->getOption('export');
        $apply = $input->getOption('apply');

        if (\is_string($export) && '' !== $export) {
            return $this->export($io, $export);
        }
        if (\is_string($apply) && '' !== $apply) {
            return $this->apply($io, $apply, (bool) $input->getOption('dry-run'));
        }
        $io->error('Pass --export <file> or --apply <file>.');

        return Command::INVALID;
    }

    private function export(SymfonyStyle $io, string $path): int
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, ST_Y(geom) AS lat, ST_X(geom) AS lng FROM item
              WHERE letter = 'P' AND state <> 'retired' ORDER BY id",
        );
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
        ], $rows);
        file_put_contents($path, json_encode($items, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        $io->success(\sprintf('Wrote %d scenic item(s) to %s.', \count($items), $path));

        return Command::SUCCESS;
    }

    private function apply(SymfonyStyle $io, string $path, bool $dryRun): int
    {
        if (!is_file($path)) {
            $io->error(\sprintf('No measurement at %s.', $path));

            return Command::INVALID;
        }
        /** @var array{withinM: int|float, items: list<array{id: int, covered: bool, nearest_bikeway_m: int|null}>} $review */
        $review = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        $within = (float) $review['withinM'];

        $failing = [];
        $uncovered = [];
        foreach ($review['items'] as $row) {
            if (!$row['covered']) {
                $uncovered[] = (int) $row['id'];
            } elseif (null === $row['nearest_bikeway_m'] || $row['nearest_bikeway_m'] > $within) {
                $failing[(int) $row['id']] = $row['nearest_bikeway_m'];
            }
        }

        $retire = [];
        $person = [];
        foreach ($this->describe(array_keys($failing)) as $item) {
            $line = \sprintf('%s (#%d, %s, %s)', $item['name'], $item['id'], $item['source'],
                null === $failing[$item['id']] ? 'no bike way in range' : $failing[$item['id']].' m from a bike way');
            if ($this->touched($item)) {
                $person[] = $line;
            } else {
                $retire[$item['id']] = $line;
            }
        }

        if (!$dryRun && [] !== $retire) {
            $this->db->executeStatement(
                "UPDATE item SET state = 'retired', updated_at = NOW() WHERE id IN (:ids) AND letter = 'P'",
                ['ids' => array_keys($retire)],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }

        $io->section(\sprintf('%s %d scenic item(s) more than %d m from a bike way', $dryRun ? 'Would retire' : 'Retired', \count($retire), (int) $within));
        $io->listing([] === $retire ? ['none'] : array_values($retire));
        if ([] !== $person) {
            $io->section(\sprintf('%d out of range but need a person: verified, edited, confirmed, best-of, or added by a person', \count($person)));
            $io->listing($person);
        }
        if ([] !== $uncovered) {
            $io->section(\sprintf('%d not measured: no local OSM extract covers them', \count($uncovered)));
            $io->listing(array_map(static fn (array $i): string => \sprintf('%s (#%d)', $i['name'], $i['id']), $this->describe($uncovered)));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array{id: int, name: string, source: string, state: string, cur: bool, edited: bool, confirmed: bool}>
     */
    private function describe(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.name, i.source, i.state,
                    COALESCE(i.attributes ->> 'cur', '') NOT IN ('', 'false', '0') AS cur,
                    EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = i.id) AS edited,
                    EXISTS (SELECT 1 FROM item_confirmation c WHERE c.item_id = i.id) AS confirmed
               FROM item i WHERE i.id IN (:ids) ORDER BY i.name",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'source' => (string) $r['source'],
            'state' => (string) $r['state'], 'cur' => (bool) $r['cur'], 'edited' => (bool) $r['edited'],
            'confirmed' => (bool) $r['confirmed'],
        ], $rows);
    }

    /** @param array{id: int, name: string, source: string, state: string, cur: bool, edited: bool, confirmed: bool} $item */
    private function touched(array $item): bool
    {
        return 'unverified' !== $item['state']
            || !\in_array($item['source'], self::HARVESTED, true)
            || $item['cur'] || $item['edited'] || $item['confirmed'];
    }
}
