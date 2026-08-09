<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ItemState;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\Connection;

/**
 * Raw-DBAL read model for the Routes desk. Proposals have no Submission row,
 * so this reads recommended_route WHERE state='submitted' directly, oldest
 * first, each row carrying its region's active count vs cap.
 *
 * @see docs/specs/route-domain.md §5
 *
 * @api Consumed by RouteModerateController.
 */
final class RouteQueue
{
    /**
     * Rows per desk page. Matches SubmissionQueue's, so a curator moving
     * between the two desks meets the same page size on both.
     */
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function pending(ModerationScope $scope, ?int $regionId, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        ['sql' => $where, 'params' => $params, 'types' => $types] = $this->pendingWhere($scope, $regionId);

        $sql = 'SELECT r.id, r.name, r.region_id, reg.name AS region_name, r.distance_m, r.ascent_m,
                       r.proposed_by, r.created_at,
                       (SELECT COUNT(*) FROM recommended_route a
                         WHERE a.state IN '.ItemState::servedSqlTuple()."
                           AND a.region_id IS NOT DISTINCT FROM r.region_id) AS active_in_region
                FROM recommended_route r
                LEFT JOIN region reg ON reg.id = r.region_id
                WHERE {$where}
                ORDER BY r.created_at ASC, r.id ASC
                LIMIT :lim OFFSET :off";
        $params['lim'] = max(1, $perPage);
        $params['off'] = self::offset($page, $perPage);

        // Hoisted out of the row mapper: every row on one desk render must show
        // the same cap, and the setting is read once rather than per route.
        $cap = $this->regionCap();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'regionId' => null === $row['region_id'] ? null : (int) $row['region_id'],
            'region' => $row['region_name'],
            'km' => round(((int) $row['distance_m']) / 1000, 1),
            'ascent' => null === $row['ascent_m'] ? null : (int) $row['ascent_m'],
            'who' => 'rider#'.substr(hash('crc32b', 'cc-sub-'.$row['proposed_by']), 0, 4),
            'when' => RelativeTime::ago(new \DateTimeImmutable((string) $row['created_at']), new \DateTimeImmutable()),
            'activeInRegion' => (int) $row['active_in_region'],
            'cap' => $cap,
        ], $this->db->fetchAllAssociative($sql, $params, $types));
    }

    /** The configured per-region active-route cap (admin-editable; system-configuration.md §2). */
    public function regionCap(): int
    {
        return $this->settings->get(SettingsRegistry::ROUTE_REGION_ACTIVE_CAP);
    }

    /** @return list<array<string, mixed>> */
    public function pendingSuggestions(ModerationScope $scope, ?int $regionId, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        ['sql' => $where, 'params' => $params, 'types' => $types] = $this->suggestionWhere($scope, $regionId);

        $sql = "SELECT s.id, s.route_id, r.name AS route_name, s.reason, s.note, s.user_id, s.created_at,
                       COALESCE(jsonb_array_length(s.segments), 0) AS seg_count
                FROM route_suggestion s
                JOIN recommended_route r ON r.id = s.route_id
                WHERE {$where}
                ORDER BY s.created_at ASC, s.id ASC
                LIMIT :lim OFFSET :off";
        $params['lim'] = max(1, $perPage);
        $params['off'] = self::offset($page, $perPage);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'routeId' => (int) $row['route_id'],
            'routeName' => (string) $row['route_name'],
            'reason' => (string) $row['reason'],
            'note' => $row['note'],
            'who' => 'rider#'.substr(hash('crc32b', 'cc-sub-'.$row['user_id']), 0, 4),
            'when' => RelativeTime::ago(new \DateTimeImmutable((string) $row['created_at']), new \DateTimeImmutable()),
            'segmentCount' => (int) $row['seg_count'],
        ], $this->db->fetchAllAssociative($sql, $params, $types));
    }

    /**
     * The open-proposal WHERE, shared by {@see pending()} and its count, so a
     * pager can never disagree with the page it is paging.
     *
     * @return array{sql:string, params:array<string,mixed>, types:array<string,mixed>}
     */
    private function pendingWhere(ModerationScope $scope, ?int $regionId): array
    {
        $where = "r.state = 'submitted'";
        $params = [];
        $types = [];
        if (null !== $regionId) {
            $where .= ' AND r.region_id = :region';
            $params['region'] = $regionId;
        }
        $frag = $scope->sqlFragment('r');
        if ('' !== $frag['sql']) {
            $where .= ' AND '.$frag['sql'];
            $params += $frag['params'];
            $types += $frag['types'];
        }

        return ['sql' => $where, 'params' => $params, 'types' => $types];
    }

    /**
     * The open-correction WHERE, shared the same way.
     *
     * @return array{sql:string, params:array<string,mixed>, types:array<string,mixed>}
     */
    private function suggestionWhere(ModerationScope $scope, ?int $regionId): array
    {
        $where = "s.status = 'pending'";
        $params = [];
        $types = [];
        if (null !== $regionId) {
            $where .= ' AND r.region_id = :region';
            $params['region'] = $regionId;
        }
        // The fragment reads region_id off the JOINed recommended_route r.
        // route_suggestion itself has no region_id column.
        $frag = $scope->sqlFragment('r');
        if ('' !== $frag['sql']) {
            $where .= ' AND '.$frag['sql'];
            $params += $frag['params'];
            $types += $frag['types'];
        }

        return ['sql' => $where, 'params' => $params, 'types' => $types];
    }

    private static function offset(int $page, int $perPage): int
    {
        return max(0, (max(1, $page) - 1) * max(1, $perPage));
    }

    /**
     * How many open proposals match the desk's CURRENT view, for the pager.
     *
     * Distinct from {@see total()}: that one is the tab badge and ignores the
     * region filter on purpose (a curator who has narrowed to one region must
     * still see how much work the whole scope holds). This one counts exactly
     * what the pager is paging.
     */
    public function pendingCount(ModerationScope $scope, ?int $regionId): int
    {
        $w = $this->pendingWhere($scope, $regionId);

        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM recommended_route r WHERE {$w['sql']}", $w['params'], $w['types']);
    }

    /** How many open corrections match the desk's current view, for the pager. */
    public function pendingSuggestionsCount(ModerationScope $scope, ?int $regionId): int
    {
        $w = $this->suggestionWhere($scope, $regionId);

        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM route_suggestion s JOIN recommended_route r ON r.id = s.route_id WHERE {$w['sql']}",
            $w['params'],
            $w['types'],
        );
    }

    public function total(ModerationScope $scope): int
    {
        $frag = $scope->sqlFragment('r');
        $sql = "SELECT COUNT(*) FROM recommended_route r WHERE r.state = 'submitted'"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return (int) $this->db->fetchOne($sql, $frag['params'], $frag['types']);
    }

    /**
     * Open route corrections (route_suggestion, status=pending) in scope.
     * This is the desk's second work stream, counted for the ROUTES tab
     * badge so a waiting correction is never invisible. The scope fragment
     * reads region_id off the JOINed recommended_route (suggestions carry
     * none).
     */
    public function pendingSuggestionCount(ModerationScope $scope): int
    {
        $frag = $scope->sqlFragment('r');
        $sql = "SELECT COUNT(*) FROM route_suggestion s JOIN recommended_route r ON r.id = s.route_id
                WHERE s.status = 'pending'"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return (int) $this->db->fetchOne($sql, $frag['params'], $frag['types']);
    }

    /** @return list<array{id:int,name:string}> */
    public function regions(ModerationScope $scope): array
    {
        $frag = $scope->sqlFragment('r');
        $sql = "SELECT DISTINCT reg.id, reg.name FROM recommended_route r
                JOIN region reg ON reg.id = r.region_id
                WHERE r.state = 'submitted'"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '')
            .' ORDER BY reg.name';

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $this->db->fetchAllAssociative($sql, $frag['params'], $frag['types']));
    }
}
