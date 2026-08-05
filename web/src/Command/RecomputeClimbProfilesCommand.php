<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * Re-measures every climb that has a drawn line, replacing hand-authored
 * gradients with figures derived from the elevation source.
 *
 * The catalogue's published numbers were typed. Checked against the route
 * stored in the same seed entry, four climbs read 8.4%, 9.3%, "9%+" and 9% for
 * roads that actually range from 3.2% to 14% — a clustering that is the
 * signature of plausible-looking values rather than measurements that drifted.
 * Even the lengths were typed: one climb published 1.5 km beside a 1.75 km
 * route.
 *
 * DRY RUN BY DEFAULT. This changes numbers riders recognise, so seeing the diff
 * before writing it is the normal way to run it (climb-elevation.md §7).
 *
 * @see docs/specs/climb-elevation.md §7
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
            ->where("i.letter = 'B'")
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
                continue;   // point-only climb: nothing to measure from
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
                // Length and gain are derived too (§4), and storing them is what
                // stops the drawer measuring the whole drawn line while the bars
                // cover only the climb — La Redoute read "2.4 km" over 21 bars
                // of 100 m, which is 2.1.
                $attrs['length'] = round($p['length']);
                $attrs['gain'] = round($p['gain']);
                $attrs['avgGradient'] = $p['avgGradient'];
                $attrs['maxGradient'] = $p['maxGradient'];
                $attrs['grad'] = $p['grad'];
                // Colours the map line, at finer resolution than the bars, so
                // the darkest stretch is the steepest one and the marker sits
                // on it.
                $attrs['lineGrad'] = $p['lineGrad'];
                $attrs['demSource'] = $p['demSource'];
                $attrs['binM'] = $p['binM'];
                // A hand-placed steepest marker is the rider's, and re-deriving
                // it would silently discard a correction someone made standing
                // on the road (§5).
                $existing = $attrs['steep'] ?? null;
                if (!\is_array($existing) || true !== ($existing['manual'] ?? false)) {
                    $attrs['steep'] = $p['steep'];
                }
                // `headline` is a stored display string nothing recomputes, so
                // it drifts the moment a climb is redrawn — one climb still
                // read "1.5 km · 9% avg" on a 4.4 km line. The drawer composes
                // that line at render time now (§4).
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
