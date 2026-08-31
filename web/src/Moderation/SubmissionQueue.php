<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ItemType;
use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use App\Catalog\RiderPseudonym;
use App\Contribution\ChangeValue;
use App\Media\Entity\MediaUpload;
use App\Media\MediaStorage;
use App\Messaging\UserMessageKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Moderation queue: pending + needs-info, shared row shape for desk and map.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2
 *
 * @api
 */
final class SubmissionQueue
{
    /** Rows per page on both desks. */
    public const int PER_PAGE = 25;

    /** Change keys drawn on the map, never in the textual diff. */
    private const array SHAPE_FIELDS = ['route', 'grad', 'steep', 'steepPoint', 'segment', 'location'];

    private ?\Collator $collator = null;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly MediaStorage $mediaStorage,
        private readonly LinkVerdictStore $linkVerdicts,
    ) {
    }

    /** @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,whoUuid:string,when:string,body:string,was:string,now:string,status:string,asked:?string,riderReply:?string,priorRejection:?array{when:string,note:?string},photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>,shape:?array{before: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}, after: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}},changes:list<array{key:string,was:?string,now:string}>,linkFlag:?string}> */
    public function filtered(ModerationScope $scope, ?string $country, ?string $region, ?string $type, ?string $q = null, int $page = 1, int $perPage = self::PER_PAGE, ?int $byUser = null): array
    {
        [$where, $params] = $this->openFilters($country, $region, $type, $q, $byUser);

        return $this->rows($scope, implode(' AND ', $where), $params, $perPage, self::offset($page, $perPage));
    }

    /** How many open submissions match, for the pager. */
    public function countFiltered(ModerationScope $scope, ?string $country, ?string $region, ?string $type, ?string $q = null, ?int $byUser = null): int
    {
        [$where, $params] = $this->openFilters($country, $region, $type, $q, $byUser);
        $frag = $scope->sqlFragment('s');
        if ('' !== $frag['sql']) {
            $where[] = $frag['sql'];
            $params += $frag['params'];
        }

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM submission s LEFT JOIN region r ON r.id = s.region_id WHERE '.implode(' AND ', $where),
            $params,
            $frag['types'],
        );
    }

    /**
     * Open-queue WHERE, shared by the page and its count.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function openFilters(?string $country, ?string $region, ?string $type, ?string $q, ?int $byUser = null): array
    {
        // Legal hold leaves the desk (docs/specs/photo-uploads.md §6d).
        $where = ["s.status IN ('pending', 'needs_info')", 's.escalated_at IS NULL'];
        $params = [];
        if (null !== $country && '' !== $country) {
            $where[] = 's.country_code = :country';
            $params['country'] = $country;
        }
        if (null !== $region && '' !== $region) {
            $where[] = 'r.name = :region';
            $params['region'] = $region;
        }
        if (null !== $type && '' !== $type) {
            $where[] = 's.type = :type';
            $params['type'] = $type;
        }
        if (null !== $q && '' !== trim($q)) {
            // ILIKE wildcards escaped in the bound value, never concatenated into SQL.
            /* Title OR submitter display name. */
            $where[] = '(s.title ILIKE :q OR EXISTS (SELECT 1 FROM users qu WHERE qu.id = s.user_id AND qu.display_name ILIKE :q))';
            $params['q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)).'%';
        }
        /* One person's open work, by user id never display name. */
        if (null !== $byUser) {
            $where[] = 's.user_id = :byUser';
            $params['byUser'] = $byUser;
        }

        return [$where, $params];
    }

    private static function offset(int $page, int $perPage): int
    {
        return max(0, (max(1, $page) - 1) * $perPage);
    }

    /**
     * Map pending layer: pending only, plus optional `$focusId` for a needs-info pin.
     *
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,whoUuid:string,when:string,body:string,was:string,now:string,status:string,asked:?string,riderReply:?string,priorRejection:?array{when:string,note:?string},photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>,shape:?array{before: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}, after: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}},changes:list<array{key:string,was:?string,now:string}>,linkFlag:?string}>
     */
    public function pendingForMap(ModerationScope $scope, ?int $focusId = null): array
    {
        if (null === $focusId) {
            return $this->rows($scope, "s.status = 'pending' AND s.escalated_at IS NULL", []);
        }

        return $this->rows(
            $scope,
            "(s.status = 'pending' OR (s.id = :focus AND s.status = 'needs_info')) AND s.escalated_at IS NULL",
            ['focus' => $focusId],
        );
    }

    /**
     * Rider's own undecided submissions for their map.
     *
     * @return list<array<string, mixed>>
     */
    public function ownPendingForMap(int $userId): array
    {
        return $this->rows(
            ModerationScope::global(),
            "s.user_id = :own AND s.status IN ('pending', 'needs_info') AND s.escalated_at IS NULL",
            ['own' => $userId],
        );
    }

    /**
     * Human-readable before/after for a submission's `changes` blob.
     *
     * @return array{0: string, 1: string} was, now
     */
    private function diffStrings(string $changesJson): array
    {
        /** @var array<string, array{was: mixed, now: mixed}> $changes */
        $changes = json_decode($changesJson, true) ?: [];
        $was = [];
        $new = [];
        foreach ($changes as $field => $pair) {
            /* Geometry is reviewed on the map, never as text, exactly as
               changeRows() below already does. This loop said so in a comment
               and did not do it: an edit that changed only the shape printed
               the whole coordinate list twice, directly above the Before/After
               switch that was already showing it (owner-reported 2026-08-31).
               With every shape field skipped, a shape-only edit leaves both
               strings empty, the drawer renders no "Proposed change" block at
               all, and the map switch is the whole review, which is the point
               of having it. */
            if (\in_array($field, self::SHAPE_FIELDS, true)) {
                continue;
            }
            if (null !== ($pair['was'] ?? null)) {
                $was[] = $field.': '.ChangeValue::format($field, $pair['was']);
            }
            $new[] = $field.': '.ChangeValue::format($field, $pair['now'] ?? null);
        }

        return [implode(' · ', $was), implode(' · ', $new)];
    }

    /**
     * Settled history: approved and rejected only (docs/specs/moderation-and-contribution.md §6).
     *
     * @param ?int    $decidedBy scope to one moderator's own decisions
     * @param ?string $status    'approved' | 'rejected'; null = both
     *
     * @return list<array{id:int,itemId:?int,title:string,type:string,was:string,now:string,who:string,when:string,status:string,decidedBy:?string,note:?string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>,thread:list<array{who:string,body:string,when:string}>,sortAt:int}>
     */
    public function history(ModerationScope $scope, ?int $decidedBy = null, ?string $status = null, ?string $q = null, int $page = 1, int $perPage = self::PER_PAGE, ?string $country = null, ?string $region = null, ?string $type = null, ?int $byUser = null): array
    {
        [$where, $params, $types] = $this->settledFilters($scope, $decidedBy, $status, $q, $country, $region, $type, $byUser);
        $params['lim'] = $perPage;
        $params['off'] = self::offset($page, $perPage);

        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.item_id, s.title, s.type, s.letter, s.status, s.user_id, s.decided_at, s.decision_note,
                    s.changes, u.display_name AS decided_by_name,
                    su.public_profile, su.display_name, su.uuid AS user_uuid,
                    rr.body_text AS rider_reply
             FROM submission s LEFT JOIN users u ON u.id = s.decided_by
                  LEFT JOIN users su ON su.id = s.user_id
                  LEFT JOIN LATERAL (
                      SELECT um.body_text
                      FROM user_message um
                      WHERE um.channel = \'submission\' AND um.ref_id = s.id AND um.sender = \'rider\'
                      ORDER BY um.id DESC
                      LIMIT 1
                  ) rr ON TRUE
             WHERE '.implode(' AND ', $where).'
             ORDER BY s.decided_at DESC NULLS LAST, s.id DESC
             LIMIT :lim OFFSET :off',
            $params,
            $types,
        );
        $now = $this->clock->now();
        /* Settled photos: approved objects only; disposal may leave an empty list. */
        $photosBySubmission = $this->pendingPhotos(array_map(
            static fn (array $r): int => (int) $r['id'],
            $rows,
        ), settled: true);

        $out = array_map(function (array $r) use ($now, $photosBySubmission): array {
            // Same before/after the queue card renders.
            [$was, $new] = $this->diffStrings((string) $r['changes']);

            return [
                'id' => (int) $r['id'],
                'itemId' => null !== $r['item_id'] ? (int) $r['item_id'] : null,
                'title' => (string) $r['title'],
                'type' => (string) $r['type'],
                'letter' => (string) $r['letter'],
                /* Type label from letter; template omits null. */
                'typeLabel' => ItemType::fromLetter((string) $r['letter'])?->labelKey(),
                'was' => $was,
                'now' => $new,
                'photos' => $photosBySubmission[(int) $r['id']] ?? [],
                'who' => self::submitterLabel($r),
                'when' => null !== $r['decided_at']
                    ? RelativeTime::ago(new \DateTimeImmutable((string) $r['decided_at']), $now)
                    : '',
                'status' => (string) $r['status'],
                // Curator display name, not a pseudonym.
                'decidedBy' => null !== $r['decided_by_name'] ? (string) $r['decided_by_name'] : null,
                'note' => null !== $r['decision_note'] && '' !== $r['decision_note'] ? (string) $r['decision_note'] : null,
                'riderReply' => null !== $r['rider_reply'] && '' !== $r['rider_reply'] ? (string) $r['rider_reply'] : null,
                'sortAt' => null !== $r['decided_at'] ? (new \DateTimeImmutable((string) $r['decided_at']))->getTimestamp() : 0,
            ];
        }, $rows);

        // Trash audit is content-free and unscoped (docs/specs/moderation-and-contribution.md §6).
        $threads = $this->threadsFor(array_map(static fn (array $r): int => $r['id'], $out));
        foreach ($out as $i => $row) {
            $out[$i]['thread'] = $threads[$row['id']] ?? [];
        }

        // Trash rows only on page 1 of an unfiltered history: no shared cursor, no title, no submitter.
        if (null === $status && (null === $q || '' === trim($q)) && null === $byUser && 1 === max(1, $page)) {
            $out = array_merge($out, $this->trashed($decidedBy, $perPage, $now));
            usort($out, static fn (array $a, array $b): int => $b['sortAt'] <=> $a['sortAt']);
            $out = \array_slice($out, 0, $perPage);
        }

        return $out;
    }

    /** How many settled submissions match, for the pager. */
    public function countHistory(ModerationScope $scope, ?int $decidedBy = null, ?string $status = null, ?string $q = null, ?string $country = null, ?string $region = null, ?string $type = null, ?int $byUser = null): int
    {
        [$where, $params, $types] = $this->settledFilters($scope, $decidedBy, $status, $q, $country, $region, $type, $byUser);

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM submission s LEFT JOIN region r ON r.id = s.region_id WHERE '.implode(' AND ', $where),
            $params,
            $types,
        );
    }

    /**
     * The settled-history WHERE, shared by the page and its count.
     *
     * @return array{0: list<string>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function settledFilters(ModerationScope $scope, ?int $decidedBy, ?string $status, ?string $q, ?string $country = null, ?string $region = null, ?string $type = null, ?int $byUser = null): array
    {
        $where = ["s.status IN ('approved', 'rejected')", 's.escalated_at IS NULL'];
        $params = [];
        $types = [];
        // Same country/region/type filters as the open queue.
        if (null !== $country && '' !== $country) {
            $where[] = 's.country_code = :country';
            $params['country'] = $country;
        }
        if (null !== $region && '' !== $region) {
            $where[] = 'r.name = :region';
            $params['region'] = $region;
        }
        if (null !== $type && '' !== $type) {
            $where[] = 's.type = :type';
            $params['type'] = $type;
        }
        if (null !== $decidedBy) {
            $where[] = 's.decided_by = :me';
            $params['me'] = $decidedBy;
        }
        /* Filter by who sent, not who decided; by id never display name. */
        if (null !== $byUser) {
            $where[] = 's.user_id = :byUser';
            $params['byUser'] = $byUser;
        }
        // Ignore unknown status values from the query string.
        if (\in_array($status, ['approved', 'rejected'], true)) {
            $where[] = 's.status = :st';
            $params['st'] = $status;
        }
        if (null !== $q && '' !== trim($q)) {
            // Title OR submitter, same as the open queue.
            $where[] = '(s.title ILIKE :q OR EXISTS (SELECT 1 FROM users qu WHERE qu.id = s.user_id AND qu.display_name ILIKE :q))';
            $params['q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)).'%';
        }
        $frag = $scope->sqlFragment('s');
        if ('' !== $frag['sql']) {
            $where[] = $frag['sql'];
            $params += $frag['params'];
            $types += $frag['types'];
        }

        return [$where, $params, $types];
    }

    /**
     * Needs-info exchange per submission, oldest first. One query for the page.
     *
     * @param list<int> $submissionIds
     *
     * @return array<int, list<array{who:string,body:string,when:string}>>
     */
    private function threadsFor(array $submissionIds): array
    {
        if ([] === $submissionIds) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT ref_id, kind, body_text, created_at
             FROM user_message
             WHERE channel = 'submission' AND ref_id IN (:ids) AND kind IN (:kinds)
             ORDER BY created_at, id",
            ['ids' => $submissionIds, 'kinds' => [
                UserMessageKind::SubmissionNeedsInfo->value,
                UserMessageKind::RiderReply->value,
            ]],
            ['ids' => ArrayParameterType::INTEGER, 'kinds' => ArrayParameterType::STRING],
        );
        $now = $this->clock->now();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['ref_id']][] = [
                'who' => UserMessageKind::RiderReply->value === $r['kind'] ? 'rider' : 'curator',
                'body' => (string) $r['body_text'],
                'when' => RelativeTime::ago(new \DateTimeImmutable((string) $r['created_at']), $now),
            ];
        }

        return $out;
    }

    /**
     * Trash entries, rebuilt from the content-free audit log.
     *
     * @return list<array{id:int,itemId:?int,title:string,type:string,was:string,now:string,who:string,when:string,status:string,decidedBy:?string,note:?string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>,thread:list<array{who:string,body:string,when:string}>,sortAt:int}>
     */
    private function trashed(?int $decidedBy, int $limit, \DateTimeImmutable $now): array
    {
        $where = ['l.action = :act'];
        $params = ['act' => TrashActions::TrashSubmission, 'lim' => max(1, min(500, $limit))];
        if (null !== $decidedBy) {
            $where[] = 'l.actor_id = :me';
            $params['me'] = $decidedBy;
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT l.note, l.created_at, u.display_name AS actor_name
             FROM admin_action_log l LEFT JOIN users u ON u.id = l.actor_id
             WHERE '.implode(' AND ', $where).'
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT :lim',
            $params,
        );

        return array_map(function (array $r) use ($now): array {
            // Note is `SUB-12 · type=edit`; unexpected degrades to the raw note.
            $note = (string) $r['note'];
            preg_match('/^SUB-(\d+)(?:.*type=(\w+))?/', $note, $m);
            $at = new \DateTimeImmutable((string) $r['created_at']);

            return [
                'id' => isset($m[1]) ? (int) $m[1] : 0,
                'itemId' => null,
                'title' => isset($m[1]) ? 'SUB-'.$m[1] : $note,
                'type' => $m[2] ?? '',
                // Content-free: empty photos keep the shared row shape.
                'photos' => [],
                'who' => '',
                'when' => RelativeTime::ago($at, $now),
                'status' => 'trashed',
                'decidedBy' => null !== $r['actor_name'] ? (string) $r['actor_name'] : null,
                'note' => null,
                'riderReply' => null,
                'was' => '',
                'now' => '',
                'thread' => [],
                'sortAt' => $at->getTimestamp(),
            ];
        }, $rows);
    }

    public function total(ModerationScope $scope): int
    {
        $frag = $scope->sqlFragment('s');
        $sql = "SELECT COUNT(*) FROM submission s WHERE s.status IN ('pending', 'needs_info') AND s.escalated_at IS NULL"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return (int) $this->db->fetchOne($sql, $frag['params'], $frag['types']);
    }

    /** Status tuple for option lists; a literal per branch, never interpolated. */
    private static function statusTuple(bool $settled): string
    {
        return $settled ? "('approved', 'rejected')" : "('pending', 'needs_info')";
    }

    /** @return list<string> */
    public function countries(ModerationScope $scope, bool $settled = false): array
    {
        $frag = $scope->sqlFragment('s');
        $sql = 'SELECT DISTINCT s.country_code FROM submission s WHERE s.status IN '.self::statusTuple($settled)." AND s.escalated_at IS NULL AND s.country_code <> ''"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return $this->sortLocalized($this->db->fetchFirstColumn($sql, $frag['params'], $frag['types']));
    }

    /** @return list<string> */
    public function regions(ModerationScope $scope, bool $settled = false): array
    {
        $frag = $scope->sqlFragment('s');
        $sql = 'SELECT DISTINCT r.name FROM submission s JOIN region r ON r.id = s.region_id '
            .'WHERE s.status IN '.self::statusTuple($settled).' AND s.escalated_at IS NULL'
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return $this->sortLocalized($this->db->fetchFirstColumn($sql, $frag['params'], $frag['types']));
    }

    /**
     * @param string               $where  a WHERE body assembled ONLY from
     *                                     class-internal constant fragments
     *                                     (see filtered()/pendingForMap());
     *                                     every user-supplied value is bound
     *                                     via $params, never interpolated
     * @param array<string, mixed> $params bound query parameters
     *
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,whoUuid:string,when:string,body:string,was:string,now:string,status:string,asked:?string,riderReply:?string,priorRejection:?array{when:string,note:?string},photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>,shape:?array{before: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}, after: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}},changes:list<array{key:string,was:?string,now:string}>,linkFlag:?string}>
     */
    private function rows(ModerationScope $scope, string $where, array $params, ?int $limit = null, int $offset = 0): array
    {
        $frag = $scope->sqlFragment('s');
        if ('' !== $frag['sql']) {
            $where .= ' AND '.$frag['sql'];
            $params += $frag['params'];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.item_id, s.type, s.letter, s.country_code, COALESCE(r.name, \'\') AS region, s.title, s.status, s.decision_note,
                    ST_Y(s.geom) AS lat, ST_X(s.geom) AS lng, s.user_id, s.created_at, s.changes,
                    COALESCE(s.payload->\'details\'->>\'note\', \'\') AS body,
                    rr.body_text AS rider_reply,
                    u.public_profile, u.display_name, u.uuid AS user_uuid
             FROM submission s LEFT JOIN region r ON r.id = s.region_id
                  LEFT JOIN users u ON u.id = s.user_id
                  LEFT JOIN LATERAL (
                      SELECT um.body_text
                      FROM user_message um
                      WHERE um.channel = \'submission\' AND um.ref_id = s.id AND um.sender = \'rider\'
                      ORDER BY um.id DESC
                      LIMIT 1
                  ) rr ON TRUE
             WHERE '.$where.'
             ORDER BY s.created_at DESC, s.id DESC'
             .(null !== $limit ? ' LIMIT :lim OFFSET :off' : ''),
            $params + (null !== $limit ? ['lim' => $limit, 'off' => $offset] : []),
            $frag['types'],
        );
        $now = $this->clock->now();
        $photosBySubmission = $this->pendingPhotos(array_map(
            static fn (array $r): int => (int) $r['id'],
            $rows,
        ));
        $rejectedByItem = $this->priorRejections(array_values(array_unique(array_filter(array_map(
            static fn (array $r): ?int => null !== $r['item_id'] ? (int) $r['item_id'] : null,
            $rows,
        )))));

        $linkFlags = $this->linkFlags($rows);

        return array_map(function (array $r) use ($now, $photosBySubmission, $rejectedByItem, $linkFlags): array {
            [$was, $new] = $this->diffStrings((string) $r['changes']);

            return [
                'id' => (int) $r['id'],
                'itemId' => null !== $r['item_id'] ? (int) $r['item_id'] : null,
                'type' => (string) $r['type'],
                'letter' => (string) $r['letter'],
                'country' => (string) $r['country_code'],
                'region' => (string) $r['region'],
                'title' => (string) $r['title'],
                'lat' => (float) $r['lat'],
                'lng' => (float) $r['lng'],
                'who' => self::submitterLabel($r),
                'whoUuid' => ($r['public_profile'] ?? false) ? (string) ($r['user_uuid'] ?? '') : '',
                'when' => RelativeTime::ago(new \DateTimeImmutable((string) $r['created_at']), $now),
                'body' => (string) $r['body'],
                'was' => $was,
                'now' => $new,
                // needs_info reaches the map only via ?pending=<id>.
                'status' => (string) $r['status'],
                'asked' => null !== $r['decision_note'] && '' !== $r['decision_note'] ? (string) $r['decision_note'] : null,
                'riderReply' => null !== $r['rider_reply'] ? (string) $r['rider_reply'] : null,
                /* Prior rejection on a revived item, if any. */
                'priorRejection' => null !== $r['item_id'] ? ($rejectedByItem[(int) $r['item_id']] ?? null) : null,
                'photos' => $photosBySubmission[(int) $r['id']] ?? [],
                /* Proposed shape for the drawer before/after switch (docs/specs/moderation-and-contribution.md §5.2b). */
                'shape' => self::shapeSides((string) $r['changes']),
                /* Per-field changes for the drawer; labels stay client-side. */
                'changes' => self::changeRows((string) $r['changes']),
                /* Safe Browsing flag on proposed links; never silently reject. */
                'linkFlag' => $linkFlags[(int) $r['id']] ?? null,
            ];
        }, $rows);
    }

    /**
     * Worst link verdict per submission, one query for the page.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function linkFlags(array $rows): array
    {
        $bySubmission = [];
        foreach ($rows as $r) {
            /** @var array<string, mixed> $changes */
            $changes = json_decode((string) $r['changes'], true) ?: [];
            $links = \is_array($changes['links'] ?? null) ? ($changes['links']['now'] ?? null) : null;
            $urls = SafeBrowsing::urlsIn($links);
            if ([] !== $urls) {
                $bySubmission[(int) $r['id']] = $urls;
            }
        }
        if ([] === $bySubmission) {
            return [];
        }

        $verdicts = $this->linkVerdicts->verdictsFor(array_values(array_unique(array_merge(...array_values($bySubmission)))));

        $flags = [];
        foreach ($bySubmission as $id => $urls) {
            $flags[$id] = SafeBrowsing::worst(array_intersect_key($verdicts, array_flip($urls)));
        }

        return $flags;
    }

    /**
     * Pseudonymous unless `public_profile` (docs/specs/account-and-auth.md).
     *
     * @param array<string, mixed> $row
     */
    private static function submitterLabel(array $row): string
    {
        $name = trim((string) ($row['display_name'] ?? ''));
        if (($row['public_profile'] ?? false) && '' !== $name) {
            return $name;
        }

        return RiderPseudonym::for($row['user_id']);
    }

    /**
     * Photos per submission: `sm` thumbnail + `lg` lightbox; never `orig`
     * (docs/specs/photo-uploads.md §5).
     *
     * @param list<int> $submissionIds
     *
     * @return array<int, list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>>
     */
    private function pendingPhotos(array $submissionIds, bool $settled = false): array
    {
        if ([] === $submissionIds) {
            return [];
        }

        /* Open queue: pending; history: approved. pending_scan has no objects. */
        $statuses = $settled ? ['approved'] : ['pending'];
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, submission_id, storage_bucket, revision, taken_at, gps_distance_m
             FROM media_upload
             WHERE status IN (:statuses) AND revision IS NOT NULL AND submission_id IN (:ids)
             ORDER BY created_at ASC, id ASC',
            ['ids' => $submissionIds, 'statuses' => $statuses],
            ['ids' => ArrayParameterType::INTEGER, 'statuses' => ArrayParameterType::STRING],
        );

        $bySubmission = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $takenAt = null !== $row['taken_at']
                ? (new \DateTimeImmutable((string) $row['taken_at']))->format('Y-m')
                : null;
            $prefix = MediaUpload::prefixFor($id, (string) $row['revision']);
            $bySubmission[(int) $row['submission_id']][] = [
                'id' => $id,
                'sm' => $this->mediaStorage->url((string) $row['storage_bucket'], $prefix, 'sm'),
                'lg' => $this->mediaStorage->url((string) $row['storage_bucket'], $prefix, 'lg'),
                'takenAt' => $takenAt,
                'distanceM' => null !== $row['gps_distance_m'] ? (int) $row['gps_distance_m'] : null,
            ];
        }

        return $bySubmission;
    }

    /**
     * Most recent rejection per item, for revived materializations.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, array{when:string, note:?string}>
     */
    private function priorRejections(array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT ON (s.item_id) s.item_id, s.decided_at, s.decision_note
             FROM submission s
             WHERE s.item_id IN (:ids) AND s.status = \'rejected\'
             ORDER BY s.item_id, s.decided_at DESC NULLS LAST, s.id DESC',
            ['ids' => $itemIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $byItem = [];
        foreach ($rows as $row) {
            $note = trim((string) ($row['decision_note'] ?? ''));
            $byItem[(int) $row['item_id']] = [
                // ISO; formatted at render time, never here.
                'when' => null !== $row['decided_at']
                    ? (new \DateTimeImmutable((string) $row['decided_at']))->format(\DateTimeInterface::ATOM)
                    : '',
                'note' => '' !== $note ? $note : null,
            ];
        }

        return $byItem;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function sortLocalized(array $values): array
    {
        $strings = array_map(strval(...), $values);
        $this->collator ??= new \Collator('en');
        $this->collator->sort($strings);

        return array_values($strings);
    }

    /**
     * Before/after geometry a submission proposes, or null.
     *
     * @return array{before: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}, after: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}}|null
     */
    private static function shapeSides(string $changesJson): ?array
    {
        /** @var array<string, array{was: mixed, now: mixed}> $changes */
        $changes = json_decode($changesJson, true) ?: [];

        /* A moved pin is a shape too. */
        if (isset($changes['location'])) {
            $point = static function (string $key) use ($changes): ?array {
                $raw = $changes['location'][$key] ?? null;
                if (!\is_string($raw)) {
                    return null;
                }
                $parts = array_map('trim', explode(',', $raw));
                if (2 !== \count($parts) || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                    return null;
                }

                // `point`, not `route`: one position, not a line.
                return ['point' => [(float) $parts[0], (float) $parts[1]], 'route' => [], 'grad' => [], 'steep' => null];
            };
            $before = $point('was');
            $after = $point('now');

            return (null === $before && null === $after) ? null : ['before' => $before, 'after' => $after];
        }

        /* A road-surface stretch is a shape too. */
        if (isset($changes['segment'])) {
            $seg = static function (string $key) use ($changes): ?array {
                $side = $changes['segment'][$key] ?? null;
                if (!\is_array($side)) {
                    return null;
                }
                // Routed path when present, else the two taps.
                $line = \is_array($side['line'] ?? null) ? $side['line'] : null;
                if (null === $line) {
                    $a = $side['a'] ?? null;
                    $b = $side['b'] ?? null;
                    if (!\is_array($a) || !\is_array($b)) {
                        return null;
                    }
                    $line = [$a, $b];
                }
                // Stored [lng,lat]; renderer expects [lat,lng].
                $route = [];
                foreach ($line as $point) {
                    if (\is_array($point) && 2 === \count($point) && is_numeric($point[0]) && is_numeric($point[1])) {
                        $route[] = [(float) $point[1], (float) $point[0]];
                    }
                }

                return \count($route) >= 2 ? ['route' => $route, 'grad' => [], 'steep' => null] : null;
            };
            $before = $seg('was');
            $after = $seg('now');
            /* New stretch: Before is the unrecorded road, not empty. */
            if (null === $before && null !== $after) {
                $before = $after + ['unrecorded' => true];
            }
            if (null === $after && null === $before) {
                return null;
            }

            return ['before' => $before, 'after' => $after];
        }

        if (!isset($changes['route']) && !isset($changes['steep'])) {
            return null;
        }

        $side = static function (string $key) use ($changes): ?array {
            $route = $changes['route'][$key] ?? null;
            if (!\is_array($route) || [] === $route) {
                return null;
            }
            $grad = $changes['grad'][$key] ?? null;
            $steep = $changes['steep'][$key] ?? null;

            return [
                'route' => array_values(array_filter($route, static fn (mixed $p): bool => \is_array($p) && 2 === \count($p))),
                'grad' => \is_array($grad) ? array_values(array_filter($grad, is_numeric(...))) : [],
                'steep' => \is_array($steep) && isset($steep['at']) ? $steep : null,
            ];
        };

        $before = $side('was');
        $after = $side('now');

        return (null === $before && null === $after) ? null : ['before' => $before, 'after' => $after];
    }

    /**
     * Per-field changes. `was` is null when the field had no previous value.
     *
     * @return list<array{key: string, was: ?string, now: string}>
     */
    private static function changeRows(string $changesJson): array
    {
        /** @var array<string, mixed> $changes */
        $changes = json_decode($changesJson, true) ?: [];
        $out = [];
        foreach ($changes as $field => $pair) {
            // Geometry is reviewed on the map, never as text.
            if (\in_array($field, self::SHAPE_FIELDS, true)) {
                continue;
            }
            // Skip a payload that is not {was, now}.
            if (!\is_array($pair)) {
                continue;
            }
            $was = $pair['was'] ?? null;
            $out[] = [
                'key' => (string) $field,
                'was' => null === $was || '' === $was ? null : ChangeValue::format((string) $field, $was),
                'now' => ChangeValue::format((string) $field, $pair['now'] ?? null),
            ];
        }

        return $out;
    }
}
