<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;

/**
 * Raw-DBAL read model for the Routes desk (spec §6). Proposals have no
 * Submission row (D1), so this reads recommended_route WHERE state='submitted'
 * directly, oldest first, each row carrying its region's active count vs cap.
 *
 * @api Consumed by RouteModerateController.
 */
final class RouteQueue
{
    public function __construct(
        private readonly Connection $db,
        private readonly int $regionActiveCap,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function pending(?int $regionId): array
    {
        $where = "r.state = 'submitted'";
        $params = [];
        if (null !== $regionId) {
            $where .= ' AND r.region_id = :region';
            $params['region'] = $regionId;
        }

        $sql = "SELECT r.id, r.name, r.region_id, reg.name AS region_name, r.distance_m, r.ascent_m,
                       r.proposed_by, r.created_at,
                       (SELECT COUNT(*) FROM recommended_route a
                         WHERE a.state IN ".ItemState::servedSqlTuple()."
                           AND a.region_id IS NOT DISTINCT FROM r.region_id) AS active_in_region
                FROM recommended_route r
                LEFT JOIN region reg ON reg.id = r.region_id
                WHERE {$where}
                ORDER BY r.created_at ASC, r.id ASC";

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
            'cap' => $this->regionActiveCap,
        ], $this->db->fetchAllAssociative($sql, $params));
    }

    /** @return list<array<string, mixed>> */
    public function pendingSuggestions(?int $regionId): array
    {
        $where = "s.status = 'pending'";
        $params = [];
        if (null !== $regionId) {
            $where .= ' AND r.region_id = :region';
            $params['region'] = $regionId;
        }

        $sql = "SELECT s.id, s.route_id, r.name AS route_name, s.reason, s.note, s.user_id, s.created_at
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
        ], $this->db->fetchAllAssociative($sql, $params));
    }

    public function total(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM recommended_route WHERE state = 'submitted'");
    }

    /** @return list<array{id:int,name:string}> */
    public function regions(): array
    {
        $sql = "SELECT DISTINCT reg.id, reg.name FROM recommended_route r
                JOIN region reg ON reg.id = r.region_id
                WHERE r.state = 'submitted' ORDER BY reg.name";

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $this->db->fetchAllAssociative($sql));
    }
}
