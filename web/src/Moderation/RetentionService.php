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
 * Retention phase 1, resolved for the two decided-row kinds that are safe to
 * purge: decided rows older than `moderation.retention_months` are garbage.
 * The moderation decision is already recorded (the audit trail lives in
 * change_history and the decision itself), the rider-facing value of keeping
 * the row fades, and unbounded growth of dismissed/rejected rows is pure
 * liability.
 *
 * Deliberately NOT swept here, pending a policy decision: rejected
 * `recommended_route` rows, and `needs_info` submissions (still awaiting the
 * rider, never terminal on a timer).
 *
 * @see docs/specs/moderation-and-contribution.md §8
 *
 * @api Read by ProfileController for its lazy cutoff filter; run by
 *      ModerateController/RouteModerateController's opportunistic hook and by
 *      `app:moderation:gc`.
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

    /** How long a decided row is kept before the sweep may delete it (admin-editable; system-configuration.md §2). */
    public function retentionMonths(): int
    {
        return $this->settings->get(SettingsRegistry::MODERATION_RETENTION_MONTHS);
    }

    public function cutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('-%d months', $this->retentionMonths()));
    }

    /**
     * Deletes decided rows past the cutoff. Idempotent - safe to re-run any
     * number of times; a row already deleted simply isn't matched again.
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
            // escalated_at IS NULL: a submission under legal hold outlives its
            // retention window on purpose (docs/specs/photo-uploads.md §6d).
            // The sweep is the one deletion path that runs unattended, so it
            // is also the one most likely to quietly destroy evidence.
            // withdrawn rides the same clock as rejected: both are content
            // that is not going to the map, kept only briefly as a record.
            "DELETE FROM submission WHERE status IN ('rejected', 'withdrawn') AND decided_at < :cutoff AND escalated_at IS NULL",
            ['cutoff' => $cutoff],
        );

        return ['corrections' => $corrections, 'submissions' => $submissions];
    }

    /**
     * Fire-and-forget sweep, throttled to at most once per TTL via the
     * default cache pool - safe to call on every desk render. Never throws:
     * a failed sweep must not break the curator's page.
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
            // Opportunistic housekeeping only. No logger is wired anywhere in
            // this codebase, and a failed sweep here must never surface as a
            // broken moderation desk. The next opportunistic call (or an
            // operator running `app:moderation:gc` by hand) will simply try
            // again.
        }
    }
}
