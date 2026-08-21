<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Retention of decided rows older than `moderation.retention_months`.
 * Does not sweep rejected routes or needs_info submissions.
 *
 * @see docs/specs/moderation-and-contribution.md §8
 *
 * @api
 */
final class RetentionService
{
    private const CACHE_KEY = 'moderation_gc_last';
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly CacheInterface $cache,
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    /** How long a decided row is kept (docs/specs/system-configuration.md §2). */
    public function retentionMonths(): int
    {
        return $this->settings->get(SettingsRegistry::MODERATION_RETENTION_MONTHS);
    }

    public function cutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('-%d months', $this->retentionMonths()));
    }

    /**
     * Deletes decided rows past the cutoff. Idempotent.
     *
     * @return array{corrections: int, submissions: int}
     */
    public function sweep(): array
    {
        $cutoff = $this->cutoff()->format('Y-m-d H:i:s');

        $corrections = (int) $this->db->executeStatement(
            "DELETE FROM route_suggestion WHERE status = 'dismissed' AND resolved_at < :cutoff",
            ['cutoff' => $cutoff],
        );
        $submissions = (int) $this->db->executeStatement(
            // Legal hold outlives retention (docs/specs/photo-uploads.md §6d). Withdrawn rides the rejected clock.
            "DELETE FROM submission WHERE status IN ('rejected', 'withdrawn') AND decided_at < :cutoff AND escalated_at IS NULL",
            ['cutoff' => $cutoff],
        );

        return ['corrections' => $corrections, 'submissions' => $submissions];
    }

    /** Fire-and-forget sweep, throttled; never throws. */
    public function sweepOpportunistically(): void
    {
        try {
            $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): true {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                $this->sweep();

                return true;
            });
        } catch (\Throwable) {
            // Housekeeping only: a failed sweep must not break the desk.
        }
    }
}
