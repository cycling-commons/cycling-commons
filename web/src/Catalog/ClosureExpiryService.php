<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Retires closures whose stated window has run out. Clock starts at last observation, not created_at. Retire, never delete. `form` confirmations do not count.
 *
 * @see docs/specs/edit-items/E-hazards.md (Closures expire themselves)
 *
 * @api
 */
final class ClosureExpiryService
{
    private const string CACHE_KEY = 'catalog.closure_expiry.last_sweep';
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Every served closure that is past its window, newest observation first.
     *
     * @return list<array{id: int, name: string, closedFor: string, observedAt: string, expiresAt: string}>
     */
    public function due(): array
    {
        $now = $this->clock->now();

        /** @var list<array{id: int, name: string, attributes: string, observed_at: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            // Newest of observedOn (Scout), created_at, and the latest existence confirmation.
            "SELECT i.id, i.name, i.attributes,
                    GREATEST(
                        i.created_at,
                        COALESCE((i.attributes->>'observedOn')::timestamp, i.created_at),
                        COALESCE((SELECT MAX(c.created_at) FROM item_confirmation c
                                   WHERE c.item_id = i.id
                                     AND c.stance = 'exists'
                                     AND c.source <> 'form'), i.created_at)
                    ) AS observed_at
             FROM item i
             WHERE i.letter = 'E'
               AND i.state IN ".ItemState::servedSqlTuple()."
               AND i.attributes->>'hazardType' = :closed
             ORDER BY observed_at DESC",
            ['closed' => ClosureLifetime::CLOSED_TYPE],
        );

        $due = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $attrs */
            $attrs = json_decode($row['attributes'], true, 512, \JSON_THROW_ON_ERROR);
            $observedAt = new \DateTimeImmutable($row['observed_at']);
            $expiresAt = ClosureLifetime::expiresAt($attrs, $observedAt);
            if ($expiresAt > $now) {
                continue;
            }
            $due[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'closedFor' => (string) ($attrs[ClosureLifetime::FIELD] ?? 'Unknown'),
                'observedAt' => $observedAt->format('Y-m-d'),
                'expiresAt' => $expiresAt->format('Y-m-d'),
            ];
        }

        return $due;
    }

    /**
     * Retire everything due. Returns the rows acted on.
     *
     * @return list<array{id: int, name: string, closedFor: string, observedAt: string, expiresAt: string}>
     */
    public function sweep(): array
    {
        $due = $this->due();
        if ([] === $due) {
            return [];
        }

        foreach ($due as $row) {
            $item = $this->em->getRepository(Item::class)->find($row['id']);
            if (!$item instanceof Item || ItemState::Retired === $item->getState()) {
                continue;   // decided between the read and the write
            }
            $old = $item->getState()->value;
            $item->setState(ItemState::Retired);
            // Same append-only log as a curator decision, so expiry is not an unexplained disappearance.
            $this->em->persist((new ChangeHistory())
                ->setItemId($row['id'])
                ->setField('state')
                ->setOldValue($old)
                ->setNewValue(ItemState::Retired->value)
                ->setChangedBy(ChangeHistory::SYSTEM_ACTOR));
        }
        $this->em->flush();

        return $due;
    }

    /**
     * Sweep at most once an hour off ordinary traffic. A failed sweep must never break the map.
     */
    public function sweepOpportunistically(): void
    {
        try {
            $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): true {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                $this->sweep();

                return true;
            });
        } catch (\Throwable) {
            // Opportunistic only — a failed sweep must never turn into a broken map.
        }
    }
}
