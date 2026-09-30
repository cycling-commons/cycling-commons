<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\FindingStatus;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionStatus;
use App\Catalog\SubmissionStatus;
use App\Support\BugStatus;
use App\Support\ReportStatus;
use App\Translation\TranslationProposalStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Which desk items each curator has opened (moderation_seen).
 *
 * An item is seen by a curator once they open it: its detail page or edit
 * form loads, the map drawer opens it, or they decide it. Loading a list never
 * marks anything. The counters are untouched by any of this, they count open
 * work.
 *
 * The bar (`.is-unseen`) is for OPEN work only, and this class is the one
 * place that says so ({@see self::openWork()}): a row carries it while its item
 * is still waiting and its reader has not opened it. Once the item is dealt
 * with (decided, answered, closed, resolved, dismissed, withdrawn, a route
 * gone live or retired) nobody sees the bar on it again, on any list.
 *
 * Per curator: one curator opening an item leaves it unseen for every other,
 * until the item is dealt with. An item that is waiting again after it was
 * dealt with is forgotten for everybody ({@see self::forget()}).
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 *
 * @api
 */
final readonly class DeskSeen
{
    public function __construct(private Connection $db)
    {
    }

    /** Record that this curator opened the item. True when it was unseen until now. */
    public function mark(int $userId, SeenSubject $subject, int|string $id): bool
    {
        $id = (string) $id;
        if ('' === $id) {
            return false;
        }

        return 1 === (int) $this->db->executeStatement(
            'INSERT INTO moderation_seen (user_id, subject_type, subject_id, seen_at) VALUES (:user, :type, :id, now())
             ON CONFLICT (user_id, subject_type, subject_id) DO NOTHING',
            ['user' => $userId, 'type' => $subject->value, 'id' => $id],
        );
    }

    /**
     * Record several items of one kind at once (a route's desk page opens the
     * corrections it lists).
     *
     * @param iterable<int|string> $ids
     */
    public function markAll(int $userId, SeenSubject $subject, iterable $ids): void
    {
        $list = self::strings($ids);
        if ([] === $list) {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO moderation_seen (user_id, subject_type, subject_id, seen_at)
             SELECT :user, :type, s.id, now() FROM unnest(CAST(:ids AS text[])) AS s(id)
             ON CONFLICT (user_id, subject_type, subject_id) DO NOTHING',
            ['user' => $userId, 'type' => $subject->value, 'ids' => '{'.implode(',', array_map(self::pgArrayItem(...), $list)).'}'],
        );
    }

    /**
     * Forget who opened an item, for every curator.
     *
     * For an item that becomes waiting work again after it was dealt with (a
     * content report the author answered): it carries the bar for everybody
     * once more, including the curators who opened it the first time.
     */
    public function forget(SeenSubject $subject, int|string $id): void
    {
        $id = (string) $id;
        if ('' === $id) {
            return;
        }
        $this->db->executeStatement(
            'DELETE FROM moderation_seen WHERE subject_type = :type AND lower(subject_id) = lower(:id)',
            ['type' => $subject->value, 'id' => $id],
        );
    }

    /** Whether this curator has opened the item, open or settled. */
    public function isSeen(int $userId, SeenSubject $subject, int|string $id): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM moderation_seen WHERE user_id = :user AND subject_type = :type AND subject_id = :id',
            ['user' => $userId, 'type' => $subject->value, 'id' => (string) $id],
        );
    }

    /**
     * The ids among these that are still waiting work this curator has not
     * opened, as a set keyed by id: what a list template asks before it draws
     * its rows. One query. Settled work is never in it.
     *
     * @param iterable<int|string> $ids
     *
     * @return array<int|string, true>
     */
    public function unseenAmong(int $userId, SeenSubject $subject, iterable $ids): array
    {
        $list = self::strings($ids);
        if ([] === $list) {
            return [];
        }
        $params = ['user' => $userId, 'type' => $subject->value];
        $work = self::openWork($subject);
        if (null === $work) {
            $sql = 'SELECT s.id FROM unnest(CAST(:ids AS text[])) AS s(id)
                     WHERE NOT EXISTS (SELECT 1 FROM moderation_seen m
                                        WHERE m.user_id = :user AND m.subject_type = :type AND m.subject_id = s.id)';
            $params['ids'] = '{'.implode(',', array_map(self::pgArrayItem(...), $list)).'}';
            $types = [];
        } else {
            [$table, $open, $numeric, $statuses] = $work;
            if ([] !== $statuses) {
                $params['open'] = $statuses;
            }
            if ($numeric) {
                $list = array_values(array_filter($list, static fn (string $id): bool => 1 === preg_match('/^\d{1,18}$/', $id)));
                if ([] === $list) {
                    return [];
                }
            }
            $sql = 'SELECT t.id::text FROM '.$table.' t
                     WHERE '.($numeric ? 't.id' : 't.id::text').' IN (:ids) AND ('.$open.')
                       AND NOT EXISTS (SELECT 1 FROM moderation_seen m
                                        WHERE m.user_id = :user AND m.subject_type = :type AND m.subject_id = t.id::text)';
            $params['ids'] = $numeric ? array_map(intval(...), $list) : $list;
            $types = ['ids' => $numeric ? ArrayParameterType::INTEGER : ArrayParameterType::STRING];
            if ([] !== $statuses) {
                $types['open'] = ArrayParameterType::STRING;
            }
        }

        $unseen = [];
        foreach ($this->db->fetchFirstColumn($sql, $params, $types) as $id) {
            $unseen[(string) $id] = true;
        }

        return $unseen;
    }

    /**
     * Where a kind's items live and which of them are still waiting work: the
     * one statement of that rule for the bar. [table, SQL predicate on alias
     * `t` (`:open` is the list of open statuses), whether the id is a number,
     * the open statuses]. Null for a kind that is open for as long as its list
     * shows it: a stale translation is listed only while it is stale.
     *
     * @return array{0: string, 1: string, 2: bool, 3: list<string>}|null
     */
    private static function openWork(SeenSubject $subject): ?array
    {
        $values = static fn (array $cases): array => array_values(array_map(static fn (\BackedEnum $c): string => (string) $c->value, $cases));

        return match ($subject) {
            SeenSubject::Submission => ['submission', 't.status IN (:open)', true, $values([SubmissionStatus::Pending, SubmissionStatus::NeedsInfo])],
            SeenSubject::Route => ['recommended_route', 't.state IN (:open)', true, $values([ItemState::Submitted])],
            SeenSubject::RouteSuggestion => ['route_suggestion', 't.status IN (:open)', true, $values([RouteSuggestionStatus::Pending])],
            SeenSubject::ContentReport => ['content_report', 't.status IN (:open)', false, $values(ReportStatus::open())],
            SeenSubject::BugReport => ['bug_report', 't.status IN (:open)', true, $values(BugStatus::open())],
            SeenSubject::TranslationProposal => ['translation_proposal', 't.status IN (:open)', true, $values([TranslationProposalStatus::Pending, TranslationProposalStatus::NeedsInfo])],
            SeenSubject::CatalogFinding => ['catalog_finding', 't.status IN (:open)', true, $values([FindingStatus::Open])],
            // A removal request still on the Takedowns desk (MediaTakedownService::pendingCards()).
            SeenSubject::Takedown => ['media_upload', 't.takedown_requested_at IS NOT NULL AND t.objects_deleted_at IS NULL AND t.escalated_at IS NULL', false, []],
            SeenSubject::TranslationStale => null,
        };
    }

    /**
     * @param iterable<int|string> $ids
     *
     * @return list<string>
     */
    private static function strings(iterable $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $s = (string) $id;
            if ('' !== $s) {
                $out[$s] = $s;
            }
        }

        return array_values($out);
    }

    /** One element of a Postgres text[] literal, quoted. */
    private static function pgArrayItem(string $s): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
    }
}
