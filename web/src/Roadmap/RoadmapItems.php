<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use App\Content\ReleaseNotes;
use App\Roadmap\Entity\RoadmapItem;
use App\Roadmap\Entity\RoadmapProposal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The roadmap and the Proposals tab as the page shows them, each row with
 * its vote count and, for a signed-in rider, their own vote and follow.
 *
 * Only a live item and an open proposal are ever listed or markable: a
 * pending or declined proposal is seen by its author and the desk, nobody
 * else, and a shipped or removed item is off the roadmap. A shipped item
 * moves to "Latest additions" beside it and to the Done page.
 *
 * @phpstan-type Row array{ref: string, status: string, anchor: string, title: string, body: string, lang: string, issue: ?int, from_rider: bool, picture: ?int, votes: int, voted: bool, followed: bool}
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
final class RoadmapItems
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoadmapMarks $marks,
    ) {
    }

    /**
     * Every live item, by group and place.
     *
     * @return list<Row>
     */
    public function rows(string $locale, ?int $userId): array
    {
        $rows = [];
        $pictures = $this->publicPictures();
        foreach ($this->liveItems() as $item) {
            $text = $item->textFor($locale);
            $source = $item->getSourceProposal()?->getId();
            $rows[] = [
                'ref' => $item->getRef(),
                'status' => $item->getStatus(),
                'anchor' => 'item-'.(int) $item->getId(),
                'title' => $text?->getTitle() ?? '',
                'body' => $text?->getBody() ?? '',
                'lang' => $text?->getLocale() ?? $locale,
                'issue' => $item->getIssue(),
                'from_rider' => null !== $source,
                'picture' => null !== $source && isset($pictures[$source]) ? $source : null,
                'votes' => 0,
                'voted' => false,
                'followed' => false,
            ];
        }

        return $this->withMarks($rows, $userId);
    }

    /**
     * The items that shipped, newest first: the "Latest additions" beside the
     * roadmap (`$limit` of them) and the whole "Done" page (all of them). A
     * removed item is not here; it was dropped, not built.
     *
     * @return list<array{anchor: string, title: string, body: string, lang: string, issue: ?int, from_rider: bool, shipped: \DateTimeImmutable}>
     */
    public function shippedRows(string $locale, ?int $limit = null): array
    {
        $items = $this->em->getRepository(RoadmapItem::class)->findBy(
            ['state' => ItemState::Shipped],
            ['closedAt' => 'DESC', 'id' => 'DESC'],
            $limit,
        );
        $rows = [];
        foreach ($items as $item) {
            $text = $item->textFor($locale);
            $rows[] = [
                'anchor' => 'done-'.(int) $item->getId(),
                'title' => $text?->getTitle() ?? '',
                'body' => $text?->getBody() ?? '',
                'lang' => $text?->getLocale() ?? $locale,
                'issue' => $item->getIssue(),
                'from_rider' => null !== $item->getSourceProposal(),
                'shipped' => $item->getClosedAt() ?? $item->getUpdatedAt(),
            ];
        }

        return $rows;
    }

    /**
     * Every proposal open for votes, most votes first.
     *
     * @return list<Row>
     */
    public function proposalRows(?int $userId): array
    {
        $rows = [];
        $pictures = $this->publicPictures();
        foreach ($this->openProposals() as $proposal) {
            $rows[] = [
                'ref' => $proposal->getRef(),
                'status' => '',
                'anchor' => 'idea-'.(int) $proposal->getId(),
                'title' => $proposal->getShownTitle(),
                'body' => $proposal->getShownBody(),
                'lang' => $proposal->getLocale(),
                'issue' => null,
                'from_rider' => true,
                'picture' => isset($pictures[(int) $proposal->getId()]) ? (int) $proposal->getId() : null,
                'votes' => 0,
                'voted' => false,
                'followed' => false,
            ];
        }
        $rows = $this->withMarks($rows, $userId);
        // Stable: equal counts keep the order they were opened in.
        usort($rows, static fn (array $a, array $b): int => $b['votes'] <=> $a['votes']);

        return $rows;
    }

    /**
     * The page anchor of a markable ref (a live item or an open proposal),
     * null when the ref names nothing a rider may vote for or follow now.
     */
    public function anchorOf(string $ref): ?string
    {
        foreach ([RoadmapItem::REF_PREFIX => RoadmapItem::class, RoadmapProposal::REF_PREFIX => RoadmapProposal::class] as $prefix => $class) {
            if (!str_starts_with($ref, $prefix)) {
                continue;
            }
            $id = substr($ref, \strlen($prefix));
            if (!ctype_digit($id)) {
                return null;
            }
            $row = $this->em->find($class, (int) $id);
            if ($row instanceof RoadmapItem && $row->isLive()) {
                return 'item-'.$id;
            }
            if ($row instanceof RoadmapProposal && $row->isOpen()) {
                return 'idea-'.$id;
            }

            return null;
        }

        return null;
    }

    /**
     * Whether a proposal's picture may be served publicly right now: the
     * rider allowed it, the desk chose it, the worker drew it, and the
     * proposal is open or became an item that is still on the roadmap.
     */
    public function pictureIsPublic(RoadmapProposal $proposal): bool
    {
        if (!isset($this->publicPictures()[(int) $proposal->getId()])) {
            return false;
        }

        return $proposal->isOpen() || true === $proposal->getPromotedItem()?->isLive();
    }

    /** @return array<int, true> proposal ids whose picture is allowed, chosen and ready */
    private function publicPictures(): array
    {
        return array_fill_keys(array_map(intval(...), $this->em->getConnection()->fetchFirstColumn(
            "SELECT proposal_id FROM roadmap_proposal_image WHERE shown AND consented_at IS NOT NULL AND state = 'ready'",
        )), true);
    }

    /** @return list<RoadmapItem> by group order, then place */
    public function liveItems(): array
    {
        $items = $this->em->getRepository(RoadmapItem::class)->findBy(['state' => ItemState::Live], ['position' => 'ASC', 'id' => 'ASC']);
        $order = array_flip(ReleaseNotes::STATUSES);
        usort($items, static fn (RoadmapItem $a, RoadmapItem $b): int => ($order[$a->getStatus()] ?? 9) <=> ($order[$b->getStatus()] ?? 9));

        return $items;
    }

    /** @return list<RoadmapProposal> oldest opened first */
    public function openProposals(): array
    {
        return $this->em->getRepository(RoadmapProposal::class)->findBy(
            ['status' => ProposalStatus::Open],
            ['decidedAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * @param list<Row> $rows
     *
     * @return list<Row>
     */
    private function withMarks(array $rows, ?int $userId): array
    {
        $counts = $this->marks->voteCounts(array_column($rows, 'ref'));
        $mine = null !== $userId ? $this->marks->mine($userId) : ['votes' => [], 'follows' => []];
        foreach ($rows as $i => $row) {
            $rows[$i]['votes'] = $counts[$row['ref']] ?? 0;
            $rows[$i]['voted'] = isset($mine['votes'][$row['ref']]);
            $rows[$i]['followed'] = isset($mine['follows'][$row['ref']]);
        }

        return $rows;
    }
}
