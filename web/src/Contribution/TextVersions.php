<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Moderation\DeskRider;
use App\Translation\TranslationDiff;
use Doctrine\DBAL\Connection;

/**
 * Every version of a town text, a region's geography text and a region's
 * history text (`text_version`, moderation-and-contribution.md §3.1f): one
 * row per write, and the public list of a text's changes read from it.
 *
 * The writer and the approving curator are kept as account ids and named
 * when the list is read, the way the credit line names them: a rider by
 * their public name, else their rider# handle, a deleted account as a
 * removed rider, and a text a curator wrote themselves as its curators'.
 *
 * @api
 */
final readonly class TextVersions
{
    /** An approved suggestion, or a curator's own text that applied at once. */
    public const string APPROVAL = 'approval';
    /** A curator's own text written on a desk (the town desk, the Regions desk). */
    public const string DESK = 'desk';
    /** An approval taken back: the text before it is back, or none. */
    public const string REVOKE = 'revoke';

    public function __construct(private Connection $db)
    {
    }

    /**
     * One write of a text. `$text` is null when the local text went and the
     * page falls back to the fetched one.
     *
     * @param self::APPROVAL|self::DESK|self::REVOKE $cause
     */
    public function record(string $target, string $ref, string $lang, ?string $text, bool $derived, ?int $userId, ?int $approvedBy, ?int $submissionId, string $cause): void
    {
        $this->db->insert('text_version', [
            'target' => $target,
            'ref' => $ref,
            'lang' => $lang,
            'text' => $text,
            'derived' => $derived ? 'true' : 'false',
            'user_id' => $userId,
            'approved_by' => $approvedBy,
            'submission_id' => $submissionId,
            'cause' => $cause,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
        ]);
    }

    /**
     * A text's versions, newest first. Each names who wrote it (`curators`,
     * else `author`, null for a removed account) and, against the version
     * before it, only the words it changed (`diff`); the first version, and
     * one after the local text went, carries its whole text instead.
     *
     * @return list<array{at: \DateTimeImmutable, cause: string, text: ?string, curators: bool, author: ?string, diff: ?list<array{type: 'eq'|'del'|'ins', text: string}>}>
     */
    public function of(string $target, string $ref, string $lang): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT v.text, v.cause, v.created_at, v.user_id, v.approved_by,
                    u.pseudonym, u.display_name, u.public_profile, u.uuid
               FROM text_version v LEFT JOIN users u ON u.id = v.user_id
              WHERE v.target = :t AND v.ref = :r AND v.lang = :l
              ORDER BY v.created_at, v.id',
            ['t' => $target, 'r' => $ref, 'l' => $lang],
        );

        $out = [];
        $before = null;
        foreach ($rows as $row) {
            $text = null === $row['text'] ? null : (string) $row['text'];
            $userId = null === $row['user_id'] ? null : (int) $row['user_id'];
            $approvedBy = null === $row['approved_by'] ? null : (int) $row['approved_by'];
            // Named when somebody other than the approving curator wrote it, as on the credit line.
            $curators = $userId === $approvedBy || self::REVOKE === $row['cause'];
            $out[] = [
                'at' => new \DateTimeImmutable((string) $row['created_at']),
                'cause' => (string) $row['cause'],
                'text' => $text,
                'curators' => $curators,
                'author' => $curators || null === $userId ? null : DeskRider::of($row['pseudonym'], $row['display_name'], $row['public_profile'], $row['uuid'])['name'],
                'diff' => null !== $before && null !== $text ? TranslationDiff::around(TranslationDiff::words($before, $text)) : null,
            ];
            $before = $text;
        }

        return array_reverse($out);
    }
}
