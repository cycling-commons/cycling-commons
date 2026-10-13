<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use App\Content\ReleaseNotes;
use App\Roadmap\Entity\RoadmapItem;
use App\Roadmap\Entity\RoadmapItemText;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the roadmap desk does to items: add one, reword it, move it to
 * another group or up and down in its own, and take it off the roadmap as
 * shipped or dropped.
 *
 * Followers hear a move to another group, a ship and a removal, at once; a
 * rewording or a new place inside the same group is not news. When an item
 * leaves the roadmap its votes and follows go. Each change is one
 * transaction that holds the item's row, so two admins cannot both send the
 * same news.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
final class ItemDesk
{
    /** Room between neighbours, so a new item lands at the end without renumbering. */
    private const int STEP = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoadmapNotices $notices,
        private readonly RoadmapMarks $marks,
    ) {
    }

    /** @return list<string> the languages an item can be written in */
    public static function locales(): array
    {
        return array_keys(LocalePrefix::PATHS);
    }

    /**
     * @param array<string, array{title?: mixed, body?: mixed}> $texts per locale
     *
     * @throws RoadmapRefused
     */
    public function create(string $status, array $texts, ?int $issue): RoadmapItem
    {
        $clean = self::checkTexts($texts);
        self::checkStatus($status);

        return $this->inTransaction(function () use ($status, $clean, $issue): RoadmapItem {
            $item = $this->newItem($status, $clean);
            $item->setIssue($issue);

            return $item;
        });
    }

    /**
     * Adds an item without a transaction or a flush of its own: the caller,
     * which holds both, may be promoting a proposal.
     *
     * @param array<string, array{0: string, 1: string}> $clean checked texts, per locale
     */
    public function newItem(string $status, array $clean): RoadmapItem
    {
        $item = new RoadmapItem($status, $this->nextPosition($status));
        foreach ($clean as $locale => [$title, $body]) {
            $item->setText($locale, $title, $body);
        }
        $this->em->persist($item);

        return $item;
    }

    /**
     * Rewords an item and sets its issue; nobody is told.
     *
     * @param array<string, array{title?: mixed, body?: mixed}> $texts per locale; a locale left empty loses its text
     *
     * @throws RoadmapRefused
     */
    public function reword(RoadmapItem $item, array $texts, ?int $issue): void
    {
        $clean = self::checkTexts($texts);
        $this->inTransaction(function () use ($item, $clean, $issue): void {
            $this->lockLive($item);
            foreach (self::locales() as $locale) {
                [$title, $body] = $clean[$locale] ?? ['', ''];
                $item->setText($locale, $title, $body);
            }
            $item->setIssue($issue);
        });
    }

    /**
     * Moves an item to another group, at its end. Its followers are told.
     *
     * @throws RoadmapRefused
     */
    public function move(RoadmapItem $item, string $status): void
    {
        self::checkStatus($status);
        $this->inTransaction(function () use ($item, $status): void {
            $this->lockLive($item);
            if ($item->getStatus() === $status) {
                return;
            }
            $item->placeIn($status, $this->nextPosition($status));
            $this->notices->itemMoved($item, $status);
        });
    }

    /**
     * Swaps an item with its neighbour above (-1) or below (+1) in its group.
     * Nobody is told: the order inside a group is not a promise.
     *
     * @throws RoadmapRefused
     */
    public function shift(RoadmapItem $item, int $direction): void
    {
        $this->inTransaction(function () use ($item, $direction): void {
            $this->lockLive($item);
            $neighbour = $this->em->createQueryBuilder()
                ->select('i')->from(RoadmapItem::class, 'i')
                ->where('i.state = :live AND i.status = :status AND i.id <> :id')
                ->andWhere($direction < 0 ? 'i.position <= :pos' : 'i.position >= :pos')
                ->orderBy('i.position', $direction < 0 ? 'DESC' : 'ASC')
                ->addOrderBy('i.id', $direction < 0 ? 'DESC' : 'ASC')
                ->setParameter('live', ItemState::Live)
                ->setParameter('status', $item->getStatus())
                ->setParameter('id', (int) $item->getId())
                ->setParameter('pos', $item->getPosition())
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();
            if (!$neighbour instanceof RoadmapItem) {
                return;
            }
            $mine = $item->getPosition();
            $theirs = $neighbour->getPosition();
            if ($mine === $theirs) {
                $theirs = $mine + ($direction < 0 ? -1 : 1);
            }
            $item->placeIn($item->getStatus(), $theirs);
            $neighbour->placeIn($neighbour->getStatus(), $mine);
        });
    }

    /**
     * Takes an item off the roadmap as shipped or dropped. Its followers are
     * told and its votes and follows go.
     *
     * @throws RoadmapRefused
     */
    public function close(RoadmapItem $item, ItemState $state): void
    {
        if (ItemState::Live === $state) {
            throw new RoadmapRefused('roadmap.items.error_action');
        }
        $this->inTransaction(function () use ($item, $state): void {
            $this->lockLive($item);
            $item->close($state);
            $this->notices->itemMoved($item, ItemState::Shipped === $state ? RoadmapNotices::SHIPPED : RoadmapNotices::REMOVED);
            $this->marks->forget($item->getRef());
        });
    }

    /**
     * Checks the texts the desk typed: per locale a title (up to 120) and an
     * optional body (up to 4000), a body never without its title, and at
     * least one language filled in.
     *
     * @param array<string, array{title?: mixed, body?: mixed}> $texts
     *
     * @return array<string, array{0: string, 1: string}> the filled-in locales
     *
     * @throws RoadmapRefused
     */
    public static function checkTexts(array $texts): array
    {
        $clean = [];
        foreach (self::locales() as $locale) {
            $title = rtrim(self::text($texts[$locale]['title'] ?? null), '.');
            $body = self::text($texts[$locale]['body'] ?? null);
            if ('' === $title && '' === $body) {
                continue;
            }
            if ('' === $title) {
                throw new RoadmapRefused('roadmap.items.error_title_missing');
            }
            if (mb_strlen($title) > RoadmapItemText::TITLE_MAX) {
                throw new RoadmapRefused('roadmap.items.error_title_long');
            }
            if (mb_strlen($body) > RoadmapItemText::BODY_MAX) {
                throw new RoadmapRefused('roadmap.items.error_body_long');
            }
            $clean[$locale] = [$title, $body];
        }
        if ([] === $clean) {
            throw new RoadmapRefused('roadmap.items.error_no_text');
        }

        return $clean;
    }

    /** @throws RoadmapRefused */
    private static function checkStatus(string $status): void
    {
        if (!\in_array($status, ReleaseNotes::STATUSES, true)) {
            throw new RoadmapRefused('roadmap.items.error_group');
        }
    }

    private function nextPosition(string $status): int
    {
        $max = $this->em->getConnection()->fetchOne(
            "SELECT MAX(position) FROM roadmap_item WHERE state = 'live' AND status = :s",
            ['s' => $status],
        );

        return (is_numeric($max) ? (int) $max : 0) + self::STEP;
    }

    /** Re-reads the item under a row lock and refuses one that already left the roadmap. */
    private function lockLive(RoadmapItem $item): void
    {
        $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
        if (!$item->isLive()) {
            throw new RoadmapRefused('roadmap.items.error_closed');
        }
    }

    /**
     * DBAL's own transaction rather than wrapInTransaction(): a refusal must
     * leave the entity manager open, so the desk can show the form again.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inTransaction(\Closure $work): mixed
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $result;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
    }
}
