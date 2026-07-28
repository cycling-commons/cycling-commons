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
    public function __construct(
        private readonly Connection $db,
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function pending(ModerationScope $scope, ?int $regionId): array
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

        $sql = 'SELECT r.id, r.name, r.region_id, reg.name AS region_name, r.distance_m, r.ascent_m,
                       r.proposed_by, r.created_at,
                       (SELECT COUNT(*) FROM recommended_route a
                         WHERE a.state IN '.ItemState::servedSqlTuple()."
                           AND a.region_id IS NOT DISTINCT FROM r.region_id) AS active_in_region
                FROM recommended_route r
                LEFT JOIN region reg ON reg.id = r.region_id
                WHERE {$where}
                ORDER BY r.created_at ASC, r.id ASC";

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
    public function pendingSuggestions(ModerationScope $scope, ?int $regionId): array
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

        $sql = "SELECT s.id, s.route_id, r.name AS route_name, s.reason, s.note, s.user_id, s.created_at,
                       COALESCE(jsonb_array_length(s.segments), 0) AS seg_count
                FROM route_suggestion s
                JOIN recommended_route r ON r.id = s.route_id
                WHERE {$where}
                ORDER BY s.created_at ASC, s.id ASC";

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
