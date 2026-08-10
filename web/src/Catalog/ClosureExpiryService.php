<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * Retires closures whose stated window has run out (ClosureLifetime).
 *
 * The clock starts at the **last time somebody saw it**, not at the moment the
 * row was created: a rider confirming "still closed" pushes the expiry out by
 * the full window again, which is the "unless re-confirmed" half of the promise
 * in wiki/data-priority.md. Only confirmations that assert existence count, and
 * a `form`-sourced one never does — that is the submitter's own answer on their
 * own contribution, the same exclusion CatalogProvider's verified derivation
 * makes.
 *
 * Retire, never delete: `ItemState::Retired` is already outside
 * `servedSqlTuple()`, so the closure stops rendering with no change to any
 * serving path, and a curator can bring it back.
 *
 * @api Run by ExpireClosuresCommand and opportunistically by the map read path.
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
            // The observation date is the newest of: an explicit observedOn
            // attribute (what Scout writes, since the rider tagged it days
            // before uploading), the row's own created_at, and the most recent
            // existence confirmation.
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
             WHERE i.letter = 'F'
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
            // Recorded in the same append-only log a curator's decision lands
            // in, so an expiry is visible on the item's public history rather
            // than being an unexplained disappearance.
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
     * Sweep at most once an hour, off ordinary traffic.
     *
     * A safety net, not the mechanism: the promise is kept by the scheduled
     * command. This exists because docs/TODO.md already records that nothing
     * on the worker host runs the GC timers, and a decay nobody runs is the
     * same lie as no decay at all — so the map read path keeps it honest in
     * the meantime.
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
            // Opportunistic housekeeping only — mirrors RetentionService. A
            // failed sweep must never turn into a broken map.
        }
    }
}
