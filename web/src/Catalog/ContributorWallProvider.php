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
    /**
     * Riders per page. Larger than a moderation desk's 25 because a wall row
     * is one line, and a reader scanning for a name would rather scroll than
     * click.
     */
    public const int PER_PAGE = 60;

    /**
     * The FROM/WHERE the wall and its count share, so a pager can never
     * disagree with the page it is paging. Both filters are applied here and
     * therefore span the whole wall, not the page in front of the reader —
     * which is the reason the name search moved to the server when the wall
     * became paged: a client-side filter over one page of a growing list
     * silently answers "no such rider" about riders that are simply on
     * page 4.
     */
    private const string WALL_FROM = 'FROM users u
               LEFT JOIN world_country wc ON wc.id = u.country_id
               LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM submission
                           WHERE status = \'approved\' GROUP BY user_id) s
                      ON s.user_id = u.id
               LEFT JOIN (SELECT proposed_by, COUNT(*) AS n FROM recommended_route
                           WHERE state IN ';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{sql:string, params:array<string,mixed>}
     */
    private function wallSource(?string $q, ?string $country): array
    {
        $sql = self::WALL_FROM.ItemState::servedSqlTuple().'
                           GROUP BY proposed_by) rt
                      ON rt.proposed_by = u.id
              WHERE u.public_profile = TRUE
                AND (COALESCE(s.n, 0) + COALESCE(rt.n, 0)) > 0';
        $params = [];

        if (null !== $q && '' !== $q) {
            // ILIKE, not `=`: the box is a "find my name" search, and a rider
            // types the part they remember. The wildcards are escaped so a
            // name containing % or _ searches for itself.
            $sql .= ' AND u.display_name ILIKE :q';
            $params['q'] = '%'.addcslashes($q, '%_\\').'%';
        }
        if (null !== $country && '' !== $country) {
            $sql .= ' AND wc.name = :country';
            $params['country'] = $country;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return list<array{uuid:string, name:string, initials:string,
     *                    country:?string, facts:int, routes:int}>
     */
    public function wall(?string $q = null, ?string $country = null, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $src = $this->wallSource($q, $country);
        $params = $src['params'] + [
            'lim' => max(1, $perPage),
            'off' => max(0, (max(1, $page) - 1) * max(1, $perPage)),
        ];

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.uuid, u.display_name, wc.name AS country_name,
                    COALESCE(s.n, 0) AS facts, COALESCE(rt.n, 0) AS routes
               '.$src['sql'].'
              ORDER BY LOWER(u.display_name) ASC, u.id ASC
              LIMIT :lim OFFSET :off',
            $params,
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

    /** How many riders the wall holds under these filters, for the pager. */
    public function wallCount(?string $q = null, ?string $country = null): int
    {
        $src = $this->wallSource($q, $country);

        return (int) $this->db->fetchOne('SELECT COUNT(*) '.$src['sql'], $src['params']);
    }

    /**
     * The countries with at least one visible rider behind them, for the
     * filter's options. Built from the UNFILTERED wall — narrowing to one
     * country must not leave the select holding only that country, the same
     * rule the Regions desk follows.
     *
     * @return list<string>
     */
    public function wallCountries(): array
    {
        $src = $this->wallSource(null, null);

        /** @var list<string> $names */
        $names = $this->db->fetchFirstColumn(
            'SELECT DISTINCT wc.name '.$src['sql'].' AND wc.name IS NOT NULL ORDER BY wc.name',
            $src['params'],
        );

        return $names;
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
