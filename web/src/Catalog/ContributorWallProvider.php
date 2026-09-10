<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * /contributors wall: opt-in (`public_profile`) only, alphabetical never ranked. Must not become a surveillance surface.
 *
 * @see docs/specs/account-and-auth.md §7
 *
 * @api
 */
final class ContributorWallProvider
{
    /** Riders per page. */
    public const int PER_PAGE = 60;

    /** FROM/WHERE shared by the wall and its count so the pager cannot disagree. */
    private const string WALL_FROM = 'FROM users u
               LEFT JOIN world_country wc ON wc.id = u.country_id
               LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM submission
                           WHERE status = \'approved\' GROUP BY user_id) s
                      ON s.user_id = u.id
               LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM submission
                           WHERE status = \'approved\' AND type = \'new\' AND letter = :climbs GROUP BY user_id) cl
                      ON cl.user_id = u.id
               LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM media_upload
                           WHERE status = \'approved\' AND objects_deleted_at IS NULL GROUP BY user_id) ph
                      ON ph.user_id = u.id
               LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM item_confirmation GROUP BY user_id) ck
                      ON ck.user_id = u.id
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
        $params = ['climbs' => ItemType::Climbs->letter()];

        if (null !== $q && '' !== $q) {
            // ILIKE, wildcards escaped so `%`/`_` in a name search for themselves.
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
     *                    country:?string, facts:int, routes:int, climbs:int,
     *                    photos:int, checks:int, curator:bool}>
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
            'SELECT u.uuid, u.display_name, u.roles, wc.name AS country_name,
                    COALESCE(s.n, 0) AS facts, COALESCE(rt.n, 0) AS routes,
                    COALESCE(cl.n, 0) AS climbs, COALESCE(ph.n, 0) AS photos, COALESCE(ck.n, 0) AS checks
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
                'climbs' => (int) $row['climbs'],
                'photos' => (int) $row['photos'],
                'checks' => (int) $row['checks'],
                'curator' => self::moderates((string) $row['roles']),
            ];
        }

        return $out;
    }

    /**
     * Whether a stored roles column names someone who moderates. Admin is
     * included because the role hierarchy grants admins the curator role.
     */
    private static function moderates(string $rolesJson): bool
    {
        $roles = json_decode($rolesJson, true);
        if (!\is_array($roles)) {
            return false;
        }

        return [] !== array_intersect(['ROLE_CURATOR', 'ROLE_ADMIN'], $roles);
    }

    /** How many riders the wall holds under these filters, for the pager. */
    public function wallCount(?string $q = null, ?string $country = null): int
    {
        $src = $this->wallSource($q, $country);

        return (int) $this->db->fetchOne('SELECT COUNT(*) '.$src['sql'], $src['params']);
    }

    /**
     * Filter options from the unfiltered wall — narrowing must not shrink the select to one country.
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
     * Site-wide totals count everyone, opt-in or not — an aggregate that identifies nobody.
     *
     * Climbs, photos and checks follow the rider profile's boundaries
     * (RiderProfileController): approved work only, a taken-down photo stops
     * scoring, every check stance lands in one counter.
     *
     * @return array{facts:int, contributors:int, routes:int, climbs:int, photos:int, checks:int}
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
                  WHERE state IN '.ItemState::servedSqlTuple().') AS routes,
                (SELECT COUNT(*) FROM submission
                  WHERE status = \'approved\' AND type = \'new\' AND letter = :climbs) AS climbs,
                (SELECT COUNT(*) FROM media_upload
                  WHERE status = \'approved\' AND objects_deleted_at IS NULL) AS photos,
                (SELECT COUNT(*) FROM item_confirmation) AS checks',
            ['climbs' => ItemType::Climbs->letter()],
        );

        return [
            'facts' => (int) $row['facts'],
            'contributors' => (int) $row['contributors'],
            'routes' => (int) $row['routes'],
            'climbs' => (int) $row['climbs'],
            'photos' => (int) $row['photos'],
            'checks' => (int) $row['checks'],
        ];
    }
}
