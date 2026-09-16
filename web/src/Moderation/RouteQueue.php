<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ItemState;
use App\Media\Entity\MediaUpload;
use App\Media\MediaStorage;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Routes desk read model: submitted recommended_route rows, oldest first.
 *
 * @see docs/specs/route-domain.md §5
 *
 * @api
 */
final class RouteQueue
{
    /** Rows per desk page; matches SubmissionQueue. */
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsProviderInterface $settings,
        private readonly MediaStorage $mediaStorage,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function pending(ModerationScope $scope, ?int $regionId, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        ['sql' => $where, 'params' => $params, 'types' => $types] = $this->pendingWhere($scope, $regionId);

        $sql = 'SELECT r.id, r.name, r.region_id, reg.name AS region_name, reg.slug AS region_slug, r.distance_m, r.ascent_m,
                       r.proposed_by, r.created_at, u.display_name, u.public_profile, u.uuid AS user_uuid,
                       (SELECT COUNT(*) FROM recommended_route a
                         WHERE a.state IN '.ItemState::servedSqlTuple()."
                           AND a.region_id IS NOT DISTINCT FROM r.region_id) AS active_in_region
                FROM recommended_route r
                LEFT JOIN region reg ON reg.id = r.region_id
                LEFT JOIN users u ON u.id = r.proposed_by
                WHERE {$where}
                ORDER BY r.created_at ASC, r.id ASC
                LIMIT :lim OFFSET :off";
        $params['lim'] = max(1, $perPage);
        $params['off'] = self::offset($page, $perPage);

        // One cap per render, not per row.
        $cap = $this->regionCap();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'regionId' => null === $row['region_id'] ? null : (int) $row['region_id'],
            'region' => $row['region_name'],
            'regionSlug' => $row['region_slug'],
            'km' => round(((int) $row['distance_m']) / 1000, 1),
            'ascent' => null === $row['ascent_m'] ? null : (int) $row['ascent_m'],
            'proposer' => DeskRider::of((int) $row['proposed_by'], $row['display_name'], $row['public_profile'], $row['user_uuid']),
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
                       COALESCE(jsonb_array_length(s.segments), 0) AS seg_count,
                       u.display_name, u.public_profile, u.uuid AS user_uuid
                FROM route_suggestion s
                JOIN recommended_route r ON r.id = s.route_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE {$where}
                ORDER BY s.created_at ASC, s.id ASC
                LIMIT :lim OFFSET :off";
        $params['lim'] = max(1, $perPage);
        $params['off'] = self::offset($page, $perPage);

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        $photos = $this->photosBy('route_suggestion_id', array_map(static fn (array $row): int => (int) $row['id'], $rows));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'routeId' => (int) $row['route_id'],
            'photos' => $photos[(int) $row['id']] ?? [],
            'routeName' => (string) $row['route_name'],
            'reason' => (string) $row['reason'],
            'note' => $row['note'],
            'rider' => DeskRider::of((int) $row['user_id'], $row['display_name'], $row['public_profile'], $row['user_uuid']),
            'when' => RelativeTime::ago(new \DateTimeImmutable((string) $row['created_at']), new \DateTimeImmutable()),
            'segmentCount' => (int) $row['seg_count'],
        ], $rows);
    }

    /**
     * The pending photos sent with a route's proposal, for its detail page.
     *
     * @return list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>
     *
     * @see docs/specs/photo-uploads.md §5i
     */
    public function proposalPhotos(int $routeId): array
    {
        return $this->photosBy('route_id', [$routeId], 'AND route_suggestion_id IS NULL')[$routeId] ?? [];
    }

    /**
     * Pending, published photos keyed by the claiming column: `sm` thumbnail,
     * `lg` lightbox, never `orig`, the same card shape the item queue serves
     * (SubmissionQueue, photo-uploads.md §5).
     *
     * @param 'route_id'|'route_suggestion_id' $column
     * @param list<int>                        $ids
     *
     * @return array<int, list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>>
     */
    private function photosBy(string $column, array $ids, string $extra = ''): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, {$column} AS owner, storage_bucket, revision, taken_at, gps_distance_m
             FROM media_upload
             WHERE status = 'pending' AND revision IS NOT NULL AND {$column} IN (:ids) {$extra}
             ORDER BY created_at ASC, id ASC",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $prefix = MediaUpload::prefixFor($id, (string) $row['revision']);
            $out[(int) $row['owner']][] = [
                'id' => $id,
                'sm' => $this->mediaStorage->url((string) $row['storage_bucket'], $prefix, 'sm'),
                'lg' => $this->mediaStorage->url((string) $row['storage_bucket'], $prefix, 'lg'),
                'takenAt' => null !== $row['taken_at'] ? (new \DateTimeImmutable((string) $row['taken_at']))->format('Y-m') : null,
                'distanceM' => null !== $row['gps_distance_m'] ? (int) $row['gps_distance_m'] : null,
            ];
        }

        return $out;
    }

    /**
     * Open-proposal WHERE, shared by pending() and its count.
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
     * Open-correction WHERE, shared the same way.
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
        // Scope reads region_id off the JOINed recommended_route.
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

    /** Open proposals matching the desk's current view (not the tab badge). */
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

    /** Open corrections in scope, for the ROUTES tab badge. */
    public function pendingSuggestionCount(ModerationScope $scope): int
    {
        $frag = $scope->sqlFragment('r');
        $sql = "SELECT COUNT(*) FROM route_suggestion s JOIN recommended_route r ON r.id = s.route_id
                WHERE s.status = 'pending'"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return (int) $this->db->fetchOne($sql, $frag['params'], $frag['types']);
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function regions(ModerationScope $scope): array
    {
        $frag = $scope->sqlFragment('r');
        $sql = "SELECT DISTINCT reg.id, reg.name, reg.slug FROM recommended_route r
                JOIN region reg ON reg.id = r.region_id
                WHERE r.state = 'submitted'"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '')
            .' ORDER BY reg.name';

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug']], $this->db->fetchAllAssociative($sql, $frag['params'], $frag['types']));
    }
}
