<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Whether a decision on something a rider added followed a report, read from
 * the reports on file (DSA Article 17(3)(b)).
 *
 * A curator who retires a route, rejects a proposal or moves it to Trash
 * from its own desk may be acting on a report about it, and the author may
 * already have been told "this followed a report" when that report was
 * upheld. The desk's statement must then not say "no report was involved".
 * There is no box to tick: a report on the thing itself, or on a message its
 * rider wrote in its thread, that is still waiting or was upheld, counts. A
 * report a curator found nothing in, or one that came to nothing, does not.
 *
 * @see docs/specs/content-reports.md §7
 *
 * @api
 */
final readonly class ReportedSubjects
{
    /** The report statuses that can lead to a decision on what they are about. */
    private const array STANDING = [ReportStatus::Open, ReportStatus::InProgress, ReportStatus::Upheld];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param ReportTarget|null $target  what a report on the thing itself names, with its id
     * @param string|null       $channel the thing's message thread, with its id and its rider: a report on a message the rider wrote in it counts
     */
    public function followsReport(?ReportTarget $target, ?string $targetId, ?string $channel = null, ?int $refId = null, ?int $author = null): bool
    {
        $about = [];
        $params = ['standing' => array_map(static fn (ReportStatus $s): string => $s->value, self::STANDING)];
        if (null !== $target && null !== $targetId) {
            $about[] = '(r.target_type = :target AND r.target_id = :target_id)';
            $params['target'] = $target->value;
            $params['target_id'] = $targetId;
        }
        if (null !== $channel && null !== $refId && null !== $author) {
            // What the rider wrote in the thread, the trashed messages too: a
            // thread goes to Trash with the decision that is being explained.
            $about[] = "(r.target_type = 'message' AND r.target_id IN (SELECT m.id::text FROM user_message m WHERE m.channel = :channel AND m.ref_id = :ref_id AND m.sender_id = :author))";
            $params['channel'] = $channel;
            $params['ref_id'] = $refId;
            $params['author'] = $author;
        }
        if ([] === $about) {
            return false;
        }

        return false !== $this->db->fetchOne(
            'SELECT 1 FROM content_report r WHERE r.status IN (:standing) AND ('.implode(' OR ', $about).') LIMIT 1',
            $params,
            ['standing' => ArrayParameterType::STRING],
        );
    }
}
