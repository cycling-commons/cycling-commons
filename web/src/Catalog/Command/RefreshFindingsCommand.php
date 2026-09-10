<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\CatalogScanner;
use App\Catalog\Entity\CatalogFinding;
use App\Catalog\Entity\Item;
use App\Catalog\FindingKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the curator data desk from the mechanical checks.
 *
 * Writes to `catalog_finding` and to nothing else. It never touches an item,
 * so it is safe to schedule: the desk is a queue of questions, and every
 * answer is a person's.
 *
 * Three things it must get right, and each one is a way a review queue dies:
 *
 * - **Never raise the same finding twice.** The unique index carries it, and
 *   a repeat run updates the row it already made.
 * - **Never re-raise a dismissal.** A curator who said "these are two
 *   different bunkers" must not be asked again next week. Only `open`
 *   findings are refreshed; a decided one is left exactly as it was.
 * - **Close what stopped being true.** A finding whose rows changed is marked
 *   `resolved`, not deleted and not dismissed — dismissal records a human
 *   judgement, and this was nobody's.
 *
 * @see docs/specs/catalog-data-model.md §5c
 *
 * @api
 */
#[AsCommand(
    name: 'app:catalog:findings',
    description: 'Refresh the curator data desk from the duplicate and OSM-link scans',
)]
final class RefreshFindingsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogScanner $scanner,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('letter', null, InputOption::VALUE_REQUIRED, 'Only this catalog letter');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $letter = \is_string($input->getOption('letter')) ? $input->getOption('letter') : null;

        // EVERY finding, not only the open ones. A dismissed finding whose
        // identity is missing here would be created again on the next run, and
        // "the desk keeps asking about Ligne KW" is precisely how a review
        // queue stops being read.
        /** @var array<string, CatalogFinding> $existing keyed by identity */
        $existing = [];
        foreach ($this->em->getRepository(CatalogFinding::class)->findAll() as $finding) {
            $related = $finding->getRelatedItem();
            $existing[self::identity(
                $finding->getKind(),
                (int) $finding->getItem()->getId(),
                null === $related ? null : (int) $related->getId(),
                $finding->getOsmRef(),
            )] = $finding;
        }

        $seen = [];
        $added = 0;

        foreach ($this->scanner->duplicateGroups($letter) as $group) {
            foreach ($group['losers'] as $loser) {
                $added += $this->upsert($existing, $seen, FindingKind::Duplicate,
                    (int) $loser['id'], (int) $group['keeper']['id'], null, [
                        'keeperName' => $group['keeper']['name'],
                        'keeperSource' => $group['keeper']['source'],
                        'loserSource' => $loser['source'],
                        'blocked' => $group['blocked'],
                        'nameKey' => $group['key'],
                    ]) ? 1 : 0;
            }
        }

        foreach ($this->scanner->osmLinkCandidates($letter) as $candidate) {
            // The confident band is applied by app:catalog:link-osm without
            // asking, so raising it here would ask a curator to rubber-stamp a
            // decision already made. A `taken` ref is a duplicate, and the
            // duplicate scan above has already raised it as one.
            if ($candidate['confident'] || $candidate['taken']) {
                continue;
            }
            $added += $this->upsert($existing, $seen, FindingKind::OsmLink,
                (int) $candidate['item']['id'], null, $candidate['ref'], [
                    'osmName' => $candidate['osmName'],
                    'distanceM' => round($candidate['distanceM'], 1),
                    'itemSource' => $candidate['item']['source'],
                ]) ? 1 : 0;
        }

        // Whatever was OPEN and the scan no longer finds has stopped being
        // true: the rows moved, were retired, or were linked. Decided findings
        // are skipped — a dismissal is a record of a judgement and outlives the
        // rows that prompted it.
        $resolved = 0;
        foreach ($existing as $identity => $finding) {
            if ($finding->getStatus()->isOpen() && !isset($seen[$identity])) {
                $finding->resolveAutomatically();
                ++$resolved;
            }
        }

        $this->em->flush();

        $io->success(sprintf('%d new, %d still open, %d resolved by the data changing.',
            $added, \count($seen) - $added, $resolved));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, CatalogFinding> $existing
     * @param array<string, true>           $seen
     * @param array<string, mixed>          $detail
     *
     * @return bool true when the finding is new
     */
    private function upsert(array &$existing, array &$seen, FindingKind $kind, int $itemId, ?int $relatedId, ?string $osmRef, array $detail): bool
    {
        $identity = self::identity($kind, $itemId, $relatedId, $osmRef);
        $seen[$identity] = true;

        if (isset($existing[$identity])) {
            $finding = $existing[$identity];
            if ($finding->getStatus()->isOpen()) {
                // Same finding, possibly a new distance. Refresh the detail so
                // the desk shows what is true now, and leave the row where it is.
                $finding->setDetail($detail);
            }
            // A decided finding is left untouched, detail included. Re-opening
            // it, or even quietly rewriting it, would undo a curator's answer.

            return false;
        }

        $item = $this->em->getReference(Item::class, $itemId);
        $finding = new CatalogFinding($kind, $item, $detail);
        if (null !== $relatedId) {
            $finding->setRelatedItem($this->em->getReference(Item::class, $relatedId));
        }
        $finding->setOsmRef($osmRef);
        $this->em->persist($finding);

        return true;
    }

    private static function identity(FindingKind $kind, int $itemId, ?int $relatedId, ?string $osmRef): string
    {
        return implode('|', [$kind->value, $itemId, $relatedId ?? '-', $osmRef ?? '-']);
    }
}
