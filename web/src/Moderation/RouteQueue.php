<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ItemState;
use App\Catalog\RouteMetadata;
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

    /**
     * Every active route in one region, for the desk's reach list: a curator
     * opens any of them without finding it on the map first
     * (docs/specs/route-domain.md §5.2).
     *
     * "Active" is `ItemState::SERVED`, the same definition the cap counts
     * (`RouteModerationService::activeCountForRegion()`), so the list and the
     * "N / cap active" counter cannot disagree: the counter IS this list's
     * length. Bounded by the cap, so it takes no pager.
     *
     * Ordered by name: this is a lookup list, not a queue of work, and a
     * curator reaching for a route they already have in mind scans names.
     *
     * @return list<array{id:int, name:string, state:string, km:float, ascent:?int, missing:list<array{key:string, label:string}>}>
     */
    public function activeInRegion(ModerationScope $scope, ?int $regionId): array
    {
        $where = 'r.state IN '.ItemState::servedSqlTuple();
        $params = [];
        $types = [];
        if (null === $regionId) {
            $where .= ' AND r.region_id IS NULL';
        } else {
            $where .= ' AND r.region_id = :region';
            $params['region'] = $regionId;
        }
        $frag = $scope->sqlFragment('r');
        if ('' !== $frag['sql']) {
            $where .= ' AND '.$frag['sql'];
            $params += $frag['params'];
            $types += $frag['types'];
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.name, r.state, r.distance_m, r.ascent_m, r.attributes
             FROM recommended_route r
             WHERE {$where}
             ORDER BY r.name ASC, r.id ASC",
            $params,
            $types,
        );

        return array_map(static function (array $row): array {
            /** @var array<string, mixed> $attrs */
            $attrs = json_decode((string) $row['attributes'], true) ?: [];

            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'state' => (string) $row['state'],
                'km' => round(((int) $row['distance_m']) / 1000, 1),
                'ascent' => null === $row['ascent_m'] ? null : (int) $row['ascent_m'],
                // The registry fields nobody has filled in: the likeliest
                // reason to open this route, each under the label every other
                // surface shows it by (RouteMetadata::LABELS), never a second
                // set of words for the same field.
                'missing' => array_map(
                    static fn (string $f): array => ['key' => $f, 'label' => RouteMetadata::LABELS[$f]],
                    RouteMetadata::unsetFields($attrs),
                ),
            ];
        }, $rows);
    }

    /**
     * One region the curator may moderate, by id, so the desk can name a
     * region that holds nothing yet: "no routes are live in X" needs X even
     * when X has no rows to join against. Null when the region does not exist
     * or falls outside the curator's areas, which the desk reads as no region
     * in hand rather than naming a region they may not act in.
     *
     * @return array{id:int, name:string, slug:string}|null
     */
    public function regionInScope(ModerationScope $scope, int $regionId): ?array
    {
        // The scope predicate reads `<alias>.region_id`, so it is applied to a
        // row shaped that way rather than to `region.id` directly.
        $frag = $scope->sqlFragment('sc');
        $sql = 'SELECT reg.id, reg.name, reg.slug
                FROM region reg
                JOIN (SELECT CAST(:id AS BIGINT) AS region_id) sc ON sc.region_id = reg.id'
            .('' !== $frag['sql'] ? ' WHERE '.$frag['sql'] : '');
        $row = $this->db->fetchAssociative(
            $sql,
            ['id' => $regionId] + $frag['params'],
            ['id' => \Doctrine\DBAL\ParameterType::INTEGER] + $frag['types'],
        );

        return false === $row ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug']];
    }

    /**
     * Regions in scope that hold at least one active route, with the count,
     * for the active list's own region chooser. {@see self::regions()} is the
     * desk's filter: every region a curator's areas cover.
     *
     * @return list<array{id:int, name:string, slug:string, active:int}>
     */
    public function activeRegions(ModerationScope $scope): array
    {
        $frag = $scope->sqlFragment('r');
        $sql = 'SELECT reg.id, reg.name, reg.slug, COUNT(*) AS active
                FROM recommended_route r
                JOIN region reg ON reg.id = r.region_id
                WHERE r.state IN '.ItemState::servedSqlTuple()
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '')
            .' GROUP BY reg.id, reg.name, reg.slug ORDER BY reg.name';

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'active' => (int) $row['active'],
        ], $this->db->fetchAllAssociative($sql, $frag['params'], $frag['types']));
    }

    /** @return list<array<string, mixed>> */
    public function pendingSuggestions(ModerationScope $scope, ?int $regionId, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        ['sql' => $where, 'params' => $params, 'types' => $types] = $this->suggestionWhere($scope, $regionId);

        // The rider's latest answer on this correction's thread, the way the
        // submissions desk surfaces one (SubmissionQueue, moderation-and-contribution.md §7.3).
        $sql = "SELECT s.id, s.route_id, r.name AS route_name, s.reason, s.note, s.user_id, s.created_at, s.changes,
                       COALESCE(jsonb_array_length(s.segments), 0) AS seg_count,
                       rr.body_text AS rider_reply,
                       u.display_name, u.public_profile, u.uuid AS user_uuid
                FROM route_suggestion s
                JOIN recommended_route r ON r.id = s.route_id
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN LATERAL (
                    SELECT um.body_text
                    FROM user_message um
                    WHERE um.channel = 'correction' AND um.ref_id = s.id AND um.sender = 'rider'
                    ORDER BY um.id DESC
                    LIMIT 1
                ) rr ON TRUE
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
            // What a `metadata` correction proposes, one row per field, so the
            // curator decides on the values and not on a bare "edited".
            'changes' => RouteChangeRows::of($row['changes']),
            'riderReply' => \is_string($row['rider_reply'] ?? null) && '' !== $row['rider_reply'] ? $row['rider_reply'] : null,
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

    /**
     * The regions the desk's region filter offers. A curator with areas gets
     * every region those areas cover, whether or not anything waits there, so
     * the list is their whole patch. A global curator gets the regions where
     * the desk has something to show (a proposal or a correction waiting, or a
     * live route), because every region on earth is no choice at all.
     *
     * @return list<array{id:int,name:string,slug:string}>
     */
    public function regions(ModerationScope $scope): array
    {
        if ($scope->global) {
            $sql = "SELECT reg.id, reg.name, reg.slug FROM region reg
                    WHERE EXISTS (
                        SELECT 1 FROM recommended_route r
                        WHERE r.region_id = reg.id
                          AND (r.state = 'submitted' OR r.state IN ".ItemState::servedSqlTuple()."
                               OR EXISTS (SELECT 1 FROM route_suggestion s WHERE s.route_id = r.id AND s.status = 'pending')))
                    ORDER BY reg.name";
            $rows = $this->db->fetchAllAssociative($sql);
        } else {
            // The scope predicate reads `<alias>.region_id`, so region rows are
            // shaped that way before it is applied.
            $frag = $scope->sqlFragment('sc');
            $sql = 'SELECT sc.region_id AS id, sc.name, sc.slug
                    FROM (SELECT id AS region_id, name, slug FROM region) sc
                    WHERE '.$frag['sql'].' ORDER BY sc.name';
            $rows = $this->db->fetchAllAssociative($sql, $frag['params'], $frag['types']);
        }

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug']], $rows);
    }
}
