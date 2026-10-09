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
 * The observation is the Scout tap date when it is a plausible one
 * (observedDate()), never later than the upload; anything else is the upload
 * itself. One bad payload never breaks the sweep: the date is read in PHP,
 * never cast in SQL.
 *
 * @see docs/specs/edit-items/E-hazards.md (Closures expire themselves)
 *
 * @api
 */
final class ClosureExpiryService
{
    /**
     * How far before its upload a Scout tap may be: a year of rides. A device
     * with no clock fix reports the FIT epoch (1989-12-31), far outside it.
     */
    public const int OBSERVED_MAX_AGE_DAYS = 366;

    /** How far past its upload a tap may claim to be: a device clock a little ahead, or a time zone. */
    public const int OBSERVED_FUTURE_SKEW_HOURS = 24;

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
     * The day a rider says they were there, when it is one: an ISO date (a
     * time may follow) that is a real calendar day, no more than
     * OBSERVED_MAX_AGE_DAYS before `$reference` and no more than
     * OBSERVED_FUTURE_SKEW_HOURS after it. Null otherwise. The Scout intake
     * keeps only such a date; the sweep reads a stored one the same way.
     */
    public static function observedDate(string $raw, \DateTimeImmutable $reference): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $raw, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        try {
            $at = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        // A time PHP had to roll over (25:00) is no time.
        if (false !== \DateTimeImmutable::getLastErrors()) {
            return null;
        }
        $day = new \DateTimeImmutable($at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'), new \DateTimeZone('UTC'));
        if ($at > $reference->modify(sprintf('+%d hours', self::OBSERVED_FUTURE_SKEW_HOURS))
            || $day < $reference->setTime(0, 0)->modify(sprintf('-%d days', self::OBSERVED_MAX_AGE_DAYS))) {
            return null;
        }

        return $day;
    }

    /**
     * Every served closure that is past its window, newest observation first.
     *
     * @return list<array{id: int, name: string, closedFor: string, observedAt: string, expiresAt: string}>
     */
    public function due(): array
    {
        $now = $this->clock->now();

        /** @var list<array{id: int|string, name: string, attributes: string, created_at: string, tapped: string|null, confirmed: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            // The last sighting: the tap a Scout ride recorded (`observedAt` on
            // the submission that created the row), else the creation date, or
            // a later existence confirmation. The tap is read raw and judged in
            // PHP: a payload is free text, and one impossible date cast here
            // would stop the sweep for every closure.
            "SELECT i.id, i.name, i.attributes, i.created_at,
                    (SELECT s.payload->>'observedAt' FROM submission s
                      WHERE s.item_id = i.id AND s.type = 'new'
                      ORDER BY s.id LIMIT 1) AS tapped,
                    (SELECT MAX(c.created_at) FROM item_confirmation c
                      WHERE c.item_id = i.id
                        AND c.stance = 'exists'
                        AND c.source <> 'form') AS confirmed
             FROM item i
             WHERE i.letter = 'E'
               AND i.state IN ".ItemState::servedSqlTuple()."
               AND i.attributes->>'hazardType' = :closed",
            ['closed' => ClosureLifetime::CLOSED_TYPE],
        );

        $due = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $attrs */
            $attrs = json_decode($row['attributes'], true, 512, \JSON_THROW_ON_ERROR);
            $observedAt = $this->lastSighting($row['created_at'], $row['tapped'], $row['confirmed']);
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
                'at' => $observedAt,
            ];
        }
        usort($due, static fn (array $a, array $b): int => [$b['at'], $a['id']] <=> [$a['at'], $b['id']]);

        return array_map(static function (array $d): array {
            unset($d['at']);

            return $d;
        }, $due);
    }

    /**
     * The upload, or the plausible tap before it, or a later confirmation:
     * whichever is newest. A tap is never later than the upload.
     */
    private function lastSighting(string $createdAt, ?string $tapped, ?string $confirmed): \DateTimeImmutable
    {
        $created = new \DateTimeImmutable($createdAt);
        $tap = null !== $tapped ? self::observedDate($tapped, $created) : null;
        $sighting = null !== $tap ? min($tap, $created) : $created;
        $confirmedAt = null !== $confirmed ? new \DateTimeImmutable($confirmed) : null;

        return null !== $confirmedAt && $confirmedAt > $sighting ? $confirmedAt : $sighting;
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
