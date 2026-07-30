<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Read side of the DB-driven /contributors wall. Replaces the demo's sample
 * handles with real riders under two hard rules:
 *
 * 1. **Opt-in only.** A rider appears solely when their `public_profile`
 *    toggle is on — the same switch that gates /riders/{uuid}. Everyone
 *    else contributes uncredited by design; the wall must never become a
 *    surveillance surface.
 * 2. **Non-ranked.** Alphabetical by display name, exactly as the page
 *    copy promises. Counts are shown per row but never ordered by.
 *
 * Facts = approved submissions; routes = proposals in publicly-served
 * states. Both are already public per-rider on the profile page, so the
 * wall reveals nothing new — it only aggregates what opt-in riders show.
 *
 * Per-request raw DBAL, no cache — the RegionDirectoryProvider posture.
 *
 * @api Consumed by PageController::contributors.
 */
final class ContributorWallProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array{uuid:string, name:string, initials:string,
     *                    country:?string, facts:int, routes:int}>
     */
    public function wall(): array
    {
        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.uuid, u.display_name, wc.name AS country_name,
                    COALESCE(s.n, 0) AS facts, COALESCE(rt.n, 0) AS routes
               FROM users u
               LEFT JOIN world_country wc ON wc.id = u.country_id
               LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM submission
                           WHERE status = \'approved\' GROUP BY user_id) s
                      ON s.user_id = u.id
               LEFT JOIN (SELECT proposed_by, COUNT(*) AS n FROM recommended_route
                           WHERE state IN '.ItemState::servedSqlTuple().'
                           GROUP BY proposed_by) rt
                      ON rt.proposed_by = u.id
              WHERE u.public_profile = TRUE
                AND (COALESCE(s.n, 0) + COALESCE(rt.n, 0)) > 0
              ORDER BY LOWER(u.display_name) ASC, u.id ASC',
        );

        $out = [];
        foreach ($rows as $row) {
            $name = (string) $row['display_name'];
            $out[] = [
                'uuid' => (string) $row['uuid'],
                'name' => $name,
                'initials' => mb_strtoupper(mb_substr($name, 0, 2)),
                'country' => null === $row['country_name'] ? null : (string) $row['country_name'],
                'facts' => (int) $row['facts'],
                'routes' => (int) $row['routes'],
            ];
        }

        return $out;
    }

    /**
     * Site-wide totals — these count ALL contributors, opt-in or not:
     * an aggregate number credits the crowd without identifying anyone.
     *
     * @return array{facts:int, contributors:int, routes:int}
     */
    public function stats(): array
    {
        /** @var array<string, int|string> $row */
        $row = (array) $this->db->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM submission WHERE status = \'approved\') AS facts,
                (SELECT COUNT(*) FROM (
                    SELECT user_id FROM submission WHERE status = \'approved\'
                    UNION
                    SELECT proposed_by FROM recommended_route
                     WHERE state IN '.ItemState::servedSqlTuple().' AND proposed_by IS NOT NULL
                 ) q) AS contributors,
                (SELECT COUNT(*) FROM recommended_route
                  WHERE state IN '.ItemState::servedSqlTuple().') AS routes',
        );

        return [
            'facts' => (int) $row['facts'],
            'contributors' => (int) $row['contributors'],
            'routes' => (int) $row['routes'],
        ];
    }
}
