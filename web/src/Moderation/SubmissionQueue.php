<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\RiderPseudonym;
use App\Contribution\ChangeValue;
use App\Media\MediaStorage;
use App\Messaging\UserMessageKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * The real moderation queue, DB-backed: pending + needs-info submissions,
 * filterable by country/region/type. The row shape is a shared contract used
 * by both map.js and moderate/index.html.twig.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2
 *
 * @api Read by ModerateController and MapController.
 */
final class SubmissionQueue
{
    /** Rows per page on both desks. */
    public const int PER_PAGE = 25;

    private ?\Collator $collator = null; // created once and reused, not rebuilt per call

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly MediaStorage $mediaStorage,
    ) {
    }

    /** @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,status:string,asked:?string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>}> */
    public function filtered(ModerationScope $scope, ?string $country, ?string $region, ?string $type, ?string $q = null, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $params] = $this->openFilters($country, $region, $type, $q);

        return $this->rows($scope, implode(' AND ', $where), $params, $perPage, self::offset($page, $perPage));
    }

    /** How many open submissions match, for the pager. */
    public function countFiltered(ModerationScope $scope, ?string $country, ?string $region, ?string $type, ?string $q = null): int
    {
        [$where, $params] = $this->openFilters($country, $region, $type, $q);
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
     * The open-queue WHERE, shared by the page and its count so a pager can
     * never disagree with the rows it is paging.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function openFilters(?string $country, ?string $region, ?string $type, ?string $q): array
    {
        // s.escalated_at IS NULL: a submission under legal hold leaves the
        // desk entirely (docs/specs/photo-uploads.md §6d) — it is an admin's
        // problem now, and no curator should meet it again.
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
            // Case-insensitive contains on the submission's own title, which is
            // the item name a curator is looking for. ILIKE with the wildcards
            // in the BOUND VALUE, never concatenated into the SQL.
            $where[] = 's.title ILIKE :q';
            $params['q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)).'%';
        }

        return [$where, $params];
    }

    private static function offset(int $page, int $perPage): int
    {
        return max(0, (max(1, $page) - 1) * $perPage);
    }

    /**
     * Map pending layer: strictly pending — needs-info pins stay off the map
     * until the rider answers, because they are waiting on the rider, not on
     * a curator, and a queue of questions nobody can act on is noise.
     *
     * `$focusId` is the one exception, and it exists because the desk lists
     * needs-info rows with a "review on the map" link: an explicit
     * `/map?pending=<id>` is a request for THAT submission, so it is served
     * whatever its queue status. Without it the link landed on a map with no
     * such pin, and clicking the place underneath showed the ordinary drawer
     * — the submission looked lost. The scope guard still applies, so a
     * curator cannot reach a submission outside their areas by guessing ids.
     *
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,status:string,asked:?string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>}>
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
     * The human-readable before/after for a submission's `changes` blob.
     *
     * Shared by the queue rows and the history so a settled submission reads
     * exactly the way it read while it was pending — the history used to name
     * a title and a verdict and never say WHAT had been approved
     * (owner-reported 2026-08-03).
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
            // Geometry is summarised, not dumped: ChangeValue turns a route of
            // 200 coordinate pairs into "4.3 km · foot … → summit … · 200
            // points", which is what a curator can actually decide on.
            if (null !== ($pair['was'] ?? null)) {
                $was[] = $field.': '.ChangeValue::format($field, $pair['was']);
            }
            $new[] = $field.': '.ChangeValue::format($field, $pair['now'] ?? null);
        }

        return [implode(' · ', $was), implode(' · ', $new)];
    }

    /**
     * What has already been settled: approved and rejected submissions, newest
     * decision first.
     *
     * Deliberately only those two. Trash hard-deletes the row
     * (moderation-and-contribution.md §6) and escalation lifts it off every
     * desk (photo-uploads.md §6d), so neither can appear here and neither
     * needs a filter — the history is the complete set of decisions a curator
     * can still be asked about.
     *
     * @param ?int    $decidedBy scope to one moderator's own decisions
     * @param ?string $status    'approved' | 'rejected'; null = both
     *
     * @return list<array{id:int,itemId:?int,title:string,type:string,was:string,now:string,who:string,when:string,status:string,decidedBy:?string,note:?string,riderReply:?string,thread:list<array{who:string,body:string,when:string}>,sortAt:int}>
     */
    public function history(ModerationScope $scope, ?int $decidedBy = null, ?string $status = null, ?string $q = null, int $page = 1, int $perPage = self::PER_PAGE, ?string $country = null, ?string $region = null, ?string $type = null): array
    {
        [$where, $params, $types] = $this->settledFilters($scope, $decidedBy, $status, $q, $country, $region, $type);
        $params['lim'] = $perPage;
        $params['off'] = self::offset($page, $perPage);

        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.item_id, s.title, s.type, s.status, s.user_id, s.decided_at, s.decision_note,
                    s.changes, u.display_name AS decided_by_name,
                    rr.body_text AS rider_reply
             FROM submission s LEFT JOIN users u ON u.id = s.decided_by
                  -- The rider\'s answer follows the submission into the history.
                  -- It is not personal mail (§5.4), so the desk is the ONLY place
                  -- it exists — and once a submission is decided it drops out of
                  -- the queue, which was the last surface still showing it.
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

        $out = array_map(function (array $r) use ($now): array {
            // Same before/after the queue card renders, so a settled row says
            // WHAT was approved and not merely that something was.
            [$was, $new] = $this->diffStrings((string) $r['changes']);

            return [
                'id' => (int) $r['id'],
                'itemId' => null !== $r['item_id'] ? (int) $r['item_id'] : null,
                'title' => (string) $r['title'],
                'type' => (string) $r['type'],
                'was' => $was,
                'now' => $new,
                'who' => RiderPseudonym::for($r['user_id']),
                'when' => null !== $r['decided_at']
                    ? RelativeTime::ago(new \DateTimeImmutable((string) $r['decided_at']), $now)
                    : '',
                'status' => (string) $r['status'],
                // The moderator's own display name, not a pseudonym: curators are
                // accountable to each other for decisions, and this page is
                // curator-only.
                'decidedBy' => null !== $r['decided_by_name'] ? (string) $r['decided_by_name'] : null,
                'note' => null !== $r['decision_note'] && '' !== $r['decision_note'] ? (string) $r['decision_note'] : null,
                'riderReply' => null !== $r['rider_reply'] && '' !== $r['rider_reply'] ? (string) $r['rider_reply'] : null,
                'sortAt' => null !== $r['decided_at'] ? (new \DateTimeImmutable((string) $r['decided_at']))->getTimestamp() : 0,
            ];
        }, $rows);

        // Trashed submissions belong here too — a curator who trashed something
        // and then cannot find it anywhere reasonably wonders whether it worked
        // (owner-reported 2026-08-03). The row is gone, so this reads the
        // content-free audit the Trash wrote instead, and that is ALL it can
        // ever show: `trash_submission` deliberately records the reference and
        // type and nothing else, because preserving a trashed title would
        // preserve the spam Trash exists to destroy. Hence no title, no link,
        // and no item — the reference is the whole record.
        //
        // Unscoped on purpose: the audit carries no region (the submission that
        // had one is deleted), and with no content in the row there is nothing
        // an out-of-area curator could learn from it.
        $threads = $this->threadsFor(array_map(static fn (array $r): int => $r['id'], $out));
        foreach ($out as $i => $row) {
            $out[$i]['thread'] = $threads[$row['id']] ?? [];
        }

        // Trash rows only on the FIRST page of an unfiltered history. They come
        // from a different table with no shared cursor, so interleaving them
        // across pages would drop or repeat rows as the pager moved; page one
        // is where "did my trash work?" is actually asked.
        // ...and never under a title search: a trash audit row is content-free
        // by design (§6), so it has no title to match and would surface as an
        // unexplained hit on every query.
        if (null === $status && (null === $q || '' === trim($q)) && 1 === max(1, $page)) {
            $out = array_merge($out, $this->trashed($decidedBy, $perPage, $now));
            usort($out, static fn (array $a, array $b): int => $b['sortAt'] <=> $a['sortAt']);
            $out = \array_slice($out, 0, $perPage);
        }

        return $out;
    }

    /** How many settled submissions match, for the pager. */
    public function countHistory(ModerationScope $scope, ?int $decidedBy = null, ?string $status = null, ?string $q = null, ?string $country = null, ?string $region = null, ?string $type = null): int
    {
        [$where, $params, $types] = $this->settledFilters($scope, $decidedBy, $status, $q, $country, $region, $type);

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
    private function settledFilters(ModerationScope $scope, ?int $decidedBy, ?string $status, ?string $q, ?string $country = null, ?string $region = null, ?string $type = null): array
    {
        $where = ["s.status IN ('approved', 'rejected')", 's.escalated_at IS NULL'];
        $params = [];
        $types = [];
        // The same three the open queue filters on: a curator narrowing the
        // desk to their country should be able to narrow the record the same
        // way, with the same controls (owner, 2026-08-03).
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
        // Anything other than the two real states is ignored rather than
        // trusted into the SQL — the value arrives from a query string.
        if (\in_array($status, ['approved', 'rejected'], true)) {
            $where[] = 's.status = :st';
            $params['st'] = $status;
        }
        if (null !== $q && '' !== trim($q)) {
            $where[] = 's.title ILIKE :q';
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
     * The needs-info exchange for each submission: every question a curator
     * asked and every answer the rider sent, oldest first.
     *
     * This is the one part of a submission that lives nowhere else. The queue
     * card and the settled row show only the LATEST reply, `change_history`
     * records what was applied rather than what was asked, and the curator's
     * personal inbox no longer carries it (§5.4) — so a two-round exchange had
     * no home at all (owner-reported 2026-08-03).
     *
     * One query for the whole page rather than one per row.
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
     * @return list<array{id:int,itemId:?int,title:string,type:string,was:string,now:string,who:string,when:string,status:string,decidedBy:?string,note:?string,riderReply:?string,thread:list<array{who:string,body:string,when:string}>,sortAt:int}>
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
            // The note is written as `SUB-12 · type=edit` and is the only thing
            // there is to parse; anything unexpected degrades to the raw note
            // rather than inventing a reference.
            $note = (string) $r['note'];
            preg_match('/^SUB-(\d+)(?:.*type=(\w+))?/', $note, $m);
            $at = new \DateTimeImmutable((string) $r['created_at']);

            return [
                'id' => isset($m[1]) ? (int) $m[1] : 0,
                'itemId' => null,
                'title' => isset($m[1]) ? 'SUB-'.$m[1] : $note,
                'type' => $m[2] ?? '',
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

    /**
     * Which statuses an option list describes.
     *
     * The two desks list different sets: the queue offers the countries that
     * still have open work, the record the countries that have settled work.
     * A literal per branch, never an interpolated caller value.
     */
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
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,status:string,asked:?string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>}>
     *
     * The returned row is a deliberate shared view-model: the SAME shape is
     * consumed by both moderate/index.html.twig AND map.js (as JSON). The
     * server-side formatting below (anonymised who, relative when, ' · '-joined
     * diffs) is the single source of truth for both consumers. Moving it into
     * one template would fork the logic into client JS.
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
                    rr.body_text AS rider_reply
             FROM submission s LEFT JOIN region r ON r.id = s.region_id
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

        return array_map(function (array $r) use ($now, $photosBySubmission): array {
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
                'who' => RiderPseudonym::for($r['user_id']),
                'when' => RelativeTime::ago(new \DateTimeImmutable((string) $r['created_at']), $now),
                'body' => (string) $r['body'],
                'was' => $was,
                'now' => $new,
                // A `needs_info` row only reaches the map on an explicit
                // ?pending=<id>; the drawer says so rather than showing a card
                // indistinguishable from one still awaiting a first look.
                'status' => (string) $r['status'],
                'asked' => null !== $r['decision_note'] && '' !== $r['decision_note'] ? (string) $r['decision_note'] : null,
                'riderReply' => null !== $r['rider_reply'] ? (string) $r['rider_reply'] : null,
                'photos' => $photosBySubmission[(int) $r['id']] ?? [],
                /* The proposed SHAPE, for the drawer's before/after switch.
                   Coordinates in a text diff are not reviewable: a curator
                   cannot tell from "50.4860, 5.6927" whether the summit moved
                   somewhere sensible. The map can show it, so it does — the
                   switch redraws the climb line between what is there now and
                   what is being proposed (owner, 2026-08-03).
                   Both sides travel, rather than leaning on the loaded
                   catalog: a NEW climb has no current feature to fall back to,
                   and a changed `route` with an unchanged `grad` would
                   otherwise colour the proposed line from the wrong profile. */
                'shape' => self::shapeSides((string) $r['changes']),
                /* The same change set as `was`/`now`, but per field, so the
                   drawer can group it and — more usefully — show the proposed
                   value in place of the current one when the curator flips to
                   After. One run-on string was readable; a field-by-field
                   comparison against the item's own display is reviewable
                   (owner, 2026-08-03). Labels stay client-side: the drawer
                   already has localised ones in CC_FIELD_SCHEMA. */
                'changes' => self::changeRows((string) $r['changes']),
            ];
        }, $rows);
    }

    /**
     * Pending photos per submission, with the facts harvested from each file:
     * the capture month and how far the shot was taken from the pin. The
     * curator judges the photo with the facts
     * (docs/specs/photo-uploads.md §5).
     *
     * One query for the whole page rather than one per row.
     *
     * Both variants travel: `sm` is the 120px card thumbnail, `lg` is what the
     * curator opens in the lightbox. A 120px crop is not enough to judge
     * whether a photo shows what it claims — or whether somebody is
     * identifiable in it — which is exactly the judgement this card asks for.
     * `orig` is never offered here, as everywhere else
     * (docs/specs/photo-uploads.md §5).
     *
     * @param list<int> $submissionIds
     *
     * @return array<int, list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>>
     */
    private function pendingPhotos(array $submissionIds): array
    {
        if ([] === $submissionIds) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, submission_id, continent, taken_at, gps_distance_m
             FROM media_upload
             WHERE status = 'pending' AND submission_id IN (:ids)
             ORDER BY created_at ASC, id ASC",
            ['ids' => $submissionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $bySubmission = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $takenAt = null !== $row['taken_at']
                ? (new \DateTimeImmutable((string) $row['taken_at']))->format('Y-m')
                : null;
            $bySubmission[(int) $row['submission_id']][] = [
                'id' => $id,
                'sm' => $this->mediaStorage->url((string) $row['continent'], 'photos/'.$id, 'sm'),
                'lg' => $this->mediaStorage->url((string) $row['continent'], 'photos/'.$id, 'lg'),
                'takenAt' => $takenAt,
                'distanceM' => null !== $row['gps_distance_m'] ? (int) $row['gps_distance_m'] : null,
            ];
        }

        return $bySubmission;
    }

    /**
     * Locale-aware sort of a column of strings. Takes already-fetched values
     * (not a SQL string) so no method here accepts raw SQL text as an
     * argument.
     *
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
     * The before/after climb geometry a submission proposes, or null when it
     * proposes none.
     *
     * `changes` only carries the fields that actually changed, so each side is
     * assembled from whichever of route/grad/steep is present. A side with no
     * route is dropped: there is nothing to draw, and an empty overlay reads as
     * "the climb has no line" rather than "this field was not touched".
     *
     * @return array{before: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}}, after: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}}}|null
     */
    private static function shapeSides(string $changesJson): ?array
    {
        /** @var array<string, array{was: mixed, now: mixed}> $changes */
        $changes = json_decode($changesJson, true) ?: [];
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
     * The submission's changes, one entry per field.
     *
     * `was` is null when the field had no previous value — the "add a missing
     * field" case, which must not render a struck-out blank.
     *
     * @return list<array{key: string, was: ?string, now: string}>
     */
    private static function changeRows(string $changesJson): array
    {
        /** @var array<string, mixed> $changes */
        $changes = json_decode($changesJson, true) ?: [];
        $out = [];
        foreach ($changes as $field => $pair) {
            // A payload shape that is not {was, now} is not a diff and cannot
            // be rendered as one. Skipping beats guessing.
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
