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

    /**
     * Change keys that are GEOMETRY: drawn on the map by the before/after
     * switch, and never rendered into the textual diff.
     */
    private const array SHAPE_FIELDS = ['route', 'grad', 'steep', 'steepPoint', 'segment', 'location'];

    private ?\Collator $collator = null; // created once and reused, not rebuilt per call

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
     * The open-queue WHERE, shared by the page and its count so a pager can
     * never disagree with the rows it is paging.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function openFilters(?string $country, ?string $region, ?string $type, ?string $q, ?int $byUser = null): array
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
            /* Title OR submitter. Searching a curator application's applicant by
               name found nothing, because this only ever matched the item's
               title - and "who sent this" is a question a desk gets asked
               constantly (owner 2026-08-14). Matching the display name also
               reaches riders with NO public profile: the name is stored either
               way, and `public_profile` gates the public page, never the
               moderator's view of their own queue. */
            $where[] = '(s.title ILIKE :q OR EXISTS (SELECT 1 FROM users qu WHERE qu.id = s.user_id AND qu.display_name ILIKE :q))';
            $params['q'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)).'%';
        }
        /* One person's open work, the same filter the settled history takes.
           A reviewer who has just read somebody's record usually wants the
           other half of it: what of theirs is still waiting. By id, never by
           display name. */
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
     * A rider's OWN undecided submissions, for their map (owner 2026-08-16:
     * a pending contribution was invisible to the person who made it - only
     * curators got the pending layer). Same row shape as pendingForMap, so
     * the client renders one layer either way; scoped by user, not by
     * moderation areas (their own rows are theirs to see wherever they are),
     * and needs-info rows ride along - those are exactly the ones waiting on
     * the rider.
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
                  -- The SUBMITTER, for the same public-profile rule the open
                  -- queue follows (submitterLabel).
                  LEFT JOIN users su ON su.id = s.user_id
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
        /* The photos too. A settled row is read the same way an open one is —
           a curator checking what they approved last week wants the picture,
           not the title (owner 2026-08-12). A rejected or trashed row may have
           none left: disposal is real, and an empty list is the honest result
           rather than a broken thumbnail. */
        $photosBySubmission = $this->pendingPhotos(array_map(
            static fn (array $r): int => (int) $r['id'],
            $rows,
        ), settled: true);

        $out = array_map(function (array $r) use ($now, $photosBySubmission): array {
            // Same before/after the queue card renders, so a settled row says
            // WHAT was approved and not merely that something was.
            [$was, $new] = $this->diffStrings((string) $r['changes']);

            return [
                'id' => (int) $r['id'],
                'itemId' => null !== $r['item_id'] ? (int) $r['item_id'] : null,
                'title' => (string) $r['title'],
                'type' => (string) $r['type'],
                /* WHAT KIND of place this is, beside the title. A row reading
                   "Schellinkhouterdijk" says nothing about what was proposed,
                   while an unnamed one reads "Water & food" and is instantly
                   clear - so the type showed up only on the rows that did not
                   need it (owner 2026-08-14). Resolved from the letter here,
                   where the enum lives, rather than mapped again in Twig.
                   Null for a letter no type claims, and the template omits it. */
                'typeLabel' => ItemType::fromLetter((string) $r['letter'])?->labelKey(),
                'was' => $was,
                'now' => $new,
                'photos' => $photosBySubmission[(int) $r['id']] ?? [],
                'who' => self::submitterLabel($r),
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
        // ...nor under a per-person filter, for the same reason one rung up: the
        // audit records the reference and the type and NOT who sent it, so it
        // cannot be attributed to anybody. Merged in anyway, it would put three
        // strangers' trashed rows under "see their submissions" while a reviewer
        // is deciding whether to trust that person (found 2026-08-14).
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
        /* One person's record. `decided_by` above is who ANSWERED; this is who
           SENT, which is the question a reviewer reading a curator application
           actually has: what has this rider contributed? An id, never a name -
           display names stopped being unique on 2026-07-31. */
        if (null !== $byUser) {
            $where[] = 's.user_id = :byUser';
            $params['byUser'] = $byUser;
        }
        // Anything other than the two real states is ignored rather than
        // trusted into the SQL — the value arrives from a query string.
        if (\in_array($status, ['approved', 'rejected'], true)) {
            $where[] = 's.status = :st';
            $params['st'] = $status;
        }
        if (null !== $q && '' !== trim($q)) {
            // Same widening as the open queue: title OR submitter's name.
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
                // A trashed row is content-free by design: the content is gone,
                // and so are its photos. The empty list keeps the shared row
                // shape true rather than making every reader test for it.
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
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,whoUuid:string,when:string,body:string,was:string,now:string,status:string,asked:?string,riderReply:?string,priorRejection:?array{when:string,note:?string},photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>,shape:?array{before: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}, after: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}},changes:list<array{key:string,was:?string,now:string}>,linkFlag:?string}>
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
                // A `needs_info` row only reaches the map on an explicit
                // ?pending=<id>; the drawer says so rather than showing a card
                // indistinguishable from one still awaiting a first look.
                'status' => (string) $r['status'],
                'asked' => null !== $r['decision_note'] && '' !== $r['decision_note'] ? (string) $r['decision_note'] : null,
                'riderReply' => null !== $r['rider_reply'] ? (string) $r['rider_reply'] : null,
                /* The decision this curator may be about to reverse. A rejected
                   materialization is REVIVED rather than twinned, so the old
                   report and its rejection hang off the same item id — which is
                   what makes overturning possible, and is useless if the row
                   only ever shows the new report (found 2026-08-12 while fixing
                   the revive). Null for the common case: an item nobody has
                   turned down before. */
                'priorRejection' => null !== $r['item_id'] ? ($rejectedByItem[(int) $r['item_id']] ?? null) : null,
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
                /* The Safe Browsing verdict on the links THIS submission
                   proposes (App\Catalog\Links\SafeBrowsing). Null when it
                   proposes none, which is almost every card.

                   Read here rather than written into the `links` attribute at
                   submit, and that is the whole reason the verdict lives in its
                   own table: `links` flows through the change diff above, so a
                   verdict stored inside it would render as a rider-made edit
                   and manufacture curator work out of a background check.

                   FLAG, never silently reject (owner's rule). The submission
                   is in the queue either way, carrying its verdict, because a
                   false positive that vanishes is indistinguishable from a
                   bug. */
                'linkFlag' => $linkFlags[(int) $r['id']] ?? null,
            ];
        }, $rows);
    }

    /**
     * The worst link verdict per submission, in ONE query for the whole page.
     *
     * Keyed by url in the store, so a page of twenty cards pointing at the same
     * handful of sites costs one lookup rather than twenty.
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
     * How a submitter is named to a curator.
     *
     * Pseudonymous by default (`account-and-auth.md` §names): a decision should
     * turn on the contribution, not on who sent it.
     *
     * **Except when the rider has said otherwise.** `public_profile` is an
     * explicit opt-in that already puts their name on the contributors wall and
     * on a public `/riders/{uuid}` page, so hiding it from the one person who
     * has to read their work was inconsistent rather than protective — the
     * owner hit exactly that (2026-08-12). A private account is unchanged, and
     * that is where the protection actually matters.
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
    private function pendingPhotos(array $submissionIds, bool $settled = false): array
    {
        if ([] === $submissionIds) {
            return [];
        }

        /* Which photos exist depends on which desk is asking.

           The open queue wants the ones still awaiting a verdict. The history
           wants what was APPROVED — those objects are live and are what the
           row is a record of. Rejected media is deleted on expiry
           (photo-uploads.md §6), so listing it would put a broken thumbnail on
           an audit trail, which reads as data loss rather than as disposal
           working. */
        /* `pending_scan` is deliberately in neither list. Those rows have no
           objects at all yet (media-storage-architecture.md §3), so there is
           nothing to link a thumbnail to - and the revision filter below says
           the same thing a second time, in the one place a missing revision
           would otherwise become a broken image on a curator's card. */
        $statuses = $settled ? ['approved'] : ['pending'];
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, submission_id, storage_shard, revision, taken_at, gps_distance_m
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
                'sm' => $this->mediaStorage->url((string) $row['storage_shard'], $prefix, 'sm'),
                'lg' => $this->mediaStorage->url((string) $row['storage_shard'], $prefix, 'lg'),
                'takenAt' => $takenAt,
                'distanceM' => null !== $row['gps_distance_m'] ? (int) $row['gps_distance_m'] : null,
            ];
        }

        return $bySubmission;
    }

    /**
     * The most recent REJECTION already on record for each of these items.
     *
     * A rejection is a decision about one report, not a permanent silence on a
     * place, so a rejected row is revived when somebody proposes it again
     * (CatalogContributionService). That is the right behaviour and it creates
     * this problem: the queue row carries the new report and says nothing about
     * the verdict it is asking a curator to overturn, who then has to go
     * looking for a decision they do not know exists.
     *
     * Newest rejection per item, and only rejections — an approval is the
     * item's ordinary history and is already on the card as the change list.
     * `decided_at` can be null on rows written before it was recorded, so the
     * id breaks the tie rather than the row dropping out of the ordering.
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
                // ISO, formatted by the reader's own date preference at render
                // time (`cc_date` on the desk, CC_DATE_FORMAT in the drawer) —
                // never pre-formatted here.
                'when' => null !== $row['decided_at']
                    ? (new \DateTimeImmutable((string) $row['decided_at']))->format(\DateTimeInterface::ATOM)
                    : '',
                'note' => '' !== $note ? $note : null,
            ];
        }

        return $byItem;
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
     * @return array{before: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}, after: ?array{route: list<array{0:float,1:float}>, grad: list<int|float>, steep: ?array{at:array{0:float,1:float}, pct:string}, point?: array{0:float,1:float}, unrecorded?: true}}|null
     */
    private static function shapeSides(string $changesJson): ?array
    {
        /** @var array<string, array{was: mixed, now: mixed}> $changes */
        $changes = json_decode($changesJson, true) ?: [];

        /* A MOVED PIN is a shape as well, and the least readable of all as text:
           "52.62142, 5.13569 → 52.62117, 5.13448" tells a curator that something
           moved and nothing about whether it moved to the right place (owner
           2026-08-12). It goes to the same before/after switch, drawn as two
           points on the map they are already looking at. */
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

                // `point`, not `route`: one position, not a line. The renderer
                // draws a marker for it and a line for the others.
                return ['point' => [(float) $parts[0], (float) $parts[1]], 'route' => [], 'grad' => [], 'steep' => null];
            };
            $before = $point('was');
            $after = $point('now');

            return (null === $before && null === $after) ? null : ['before' => $before, 'after' => $after];
        }

        /* A road-surface stretch is a shape too, and it was arriving at the
           desk as a wall of raw JSON in the diff — a curator cannot review
           `{"a":[5.265,50.276],"b":…,"line":[[…]]}` (owner-reported
           2026-08-12). It draws on the map like a redrawn climb, using the same
           before/after switch: geometry is reviewed by looking at it. */
        if (isset($changes['segment'])) {
            $seg = static function (string $key) use ($changes): ?array {
                $side = $changes['segment'][$key] ?? null;
                if (!\is_array($side)) {
                    return null;
                }
                // The road-following path when the router found one, else the
                // two taps — the same fallback the item geometry uses.
                $line = \is_array($side['line'] ?? null) ? $side['line'] : null;
                if (null === $line) {
                    $a = $side['a'] ?? null;
                    $b = $side['b'] ?? null;
                    if (!\is_array($a) || !\is_array($b)) {
                        return null;
                    }
                    $line = [$a, $b];
                }
                // Stored [lng,lat]; showPendingShape() reads climb order,
                // [lat,lng]. Flipping here keeps ONE renderer for both.
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
            /* A NEW stretch has no previous geometry, so Before used to be
               unavailable — the switch rendered dead and a curator could not see
               what the proposal replaces (owner-reported 2026-08-12).

               It replaces something: the road as the map drew it, which for a
               road nobody has recorded a surface for is the red dotted "needs
               recording" line. Same geometry, marked `unrecorded`, so the
               drawer can draw it in the vocabulary the legend already uses
               rather than inventing a second way to say "no answer yet". */
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
            // Geometry is reviewed on the map (shapeSides), never as text. A
            // stretch printed as raw coordinate JSON is not something a human
            // can check, and it pushed the fields that ARE checkable off the
            // card (owner-reported 2026-08-12).
            if (\in_array($field, self::SHAPE_FIELDS, true)) {
                continue;
            }
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
