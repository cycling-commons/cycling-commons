<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Command;

use App\Catalog\Entity\Item;
use App\Elevation\ClimbProfiler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-measure climbs from the elevation source. Dry-run by default.
 *
 * @see docs/specs/climb-elevation.md §7
 *
 * @api
 */
#[AsCommand(name: 'app:climbs:recompute', description: 'Re-measure climb gradients from the elevation source')]
final class RecomputeClimbProfilesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClimbProfiler $profiler,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Persist the new values (default is a dry run)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Only this item id');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('write');

        $qb = $this->em->createQueryBuilder()
            ->select('i')->from(Item::class, 'i')
            ->where("i.letter = 'N'")
            ->orderBy('i.id', 'ASC');
        if (null !== ($id = $input->getOption('id'))) {
            $qb->andWhere('i.id = :id')->setParameter('id', (int) $id);
        }
        /** @var list<Item> $items */
        $items = $qb->getQuery()->getResult();

        $rows = [];
        $changed = 0;
        $skipped = 0;
        $flags = [];

        foreach ($items as $item) {
            $attrs = $item->getAttributes();
            $route = $attrs['route'] ?? null;
            if (!\is_array($route) || \count($route) < 2) {
                continue;
            }
            /** @var list<array{0: float, 1: float}> $coords */
            $coords = array_values(array_map(
                static fn (array $p): array => [(float) $p[0], (float) $p[1]],
                array_filter($route, static fn ($p): bool => \is_array($p) && isset($p[0], $p[1])),
            ));

            $p = $this->profiler->profile($coords);
            if (null === $p) {
                ++$skipped;
                $flags[] = \sprintf('%s (#%d): could not be measured — no elevation, or the line runs downhill', $item->getName(), $item->getId());
                continue;
            }

            $rows[] = [
                $item->getName(),
                \sprintf('%.2f km', $p['length'] / 1000),
                ($attrs['avgGradient'] ?? '—').'  →  '.$p['avgGradient'],
                ($attrs['maxGradient'] ?? '—').'  →  '.$p['maxGradient'],
            ];

            if ($p['overshootM'] > 0.0) {
                $flags[] = \sprintf(
                    '%s (#%d): runs %.0f m past its summit, losing %.0f m — measured to the summit, line left alone',
                    $item->getName(), $item->getId(), $p['overshootM'], $p['overshootDropM'],
                );
            }

            if ($write) {
                $attrs['length'] = round($p['length']);
                $attrs['gain'] = round($p['gain']);
                $attrs['footEle'] = $p['footEle'];
                $attrs['summitEle'] = $p['summitEle'];
                $attrs['avgGradient'] = $p['avgGradient'];
                $attrs['maxGradient'] = $p['maxGradient'];
                $attrs['grad'] = $p['grad'];
                $attrs['lineGrad'] = $p['lineGrad'];
                $attrs['demSource'] = $p['demSource'];
                $attrs['binM'] = $p['binM'];
                $attrs['steepWindowM'] = $p['steepWindowM'];
                // Do not overwrite a hand-placed steepest marker (docs/specs/climb-elevation.md §5).
                $existing = $attrs['steep'] ?? null;
                if (!\is_array($existing) || true !== ($existing['manual'] ?? false)) {
                    $attrs['steep'] = $p['steep'];
                }
                unset($attrs['headline']);
                $item->setAttributes($attrs);
            }
            ++$changed;
        }

        $io->title($write ? 'Recomputing climb profiles' : 'Recomputing climb profiles (DRY RUN)');
        if ([] !== $rows) {
            $io->table(['climb', 'length', 'average', 'maximum'], $rows);
        }
        foreach ($flags as $f) {
            $io->warning($f);
        }

        if ($write) {
            $this->em->flush();
            $io->success(\sprintf('%d climb(s) re-measured, %d skipped.', $changed, $skipped));
        } else {
            $io->note(\sprintf('%d climb(s) would change, %d skipped. Re-run with --write to persist.', $changed, $skipped));
        }

        return Command::SUCCESS;
    }
}
