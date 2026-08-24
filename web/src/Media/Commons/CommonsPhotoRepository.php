<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Commons;

use Doctrine\DBAL\Connection;

/**
 * Every read and write of `commons_photo`.
 *
 * Raw DBAL rather than an entity, like CoverageRepository next door: these rows
 * are a cache keyed by a third party's filename, not a domain object, and
 * nothing in the app ever holds one.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final readonly class CommonsPhotoRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Create the pending row if this file has never been asked for.
     *
     * True only for the caller that created it. Two riders opening the same
     * viewpoint in the same second both reach here, and ON CONFLICT DO NOTHING
     * lets the database pick which one dispatches, so Commons is asked once.
     */
    public function claim(string $file): bool
    {
        return 1 === $this->db->executeStatement(
            'INSERT INTO commons_photo (file, state, requested_at) VALUES (:f, :s, NOW()) ON CONFLICT (file) DO NOTHING',
            ['f' => $file, 's' => CommonsPhotoState::Pending->value],
        );
    }

    /**
     * @return array{file: string, state: string, credit: ?string, credit_user: ?string, license: ?string, storage_bucket: ?string, storage_prefix: ?string, width: ?int, height: ?int}|null
     */
    public function find(string $file): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT file, state, credit, credit_user, license, storage_bucket, storage_prefix, width, height FROM commons_photo WHERE file = :f',
            ['f' => $file],
        );
        if (false === $row) {
            return null;
        }

        // Rebuilt key by key rather than mutating what the driver handed back:
        // the width/height casts otherwise widen the shape to `...<string,
        // mixed>` and the declared return type stops meaning anything.
        return [
            'file' => (string) $row['file'],
            'state' => (string) $row['state'],
            'credit' => null === $row['credit'] ? null : (string) $row['credit'],
            'credit_user' => null === $row['credit_user'] ? null : (string) $row['credit_user'],
            'license' => null === $row['license'] ? null : (string) $row['license'],
            'storage_bucket' => null === $row['storage_bucket'] ? null : (string) $row['storage_bucket'],
            'storage_prefix' => null === $row['storage_prefix'] ? null : (string) $row['storage_prefix'],
            'width' => null === $row['width'] ? null : (int) $row['width'],
            'height' => null === $row['height'] ? null : (int) $row['height'],
        ];
    }

    public function markReady(string $file, string $bucket, string $prefix, string $credit, ?string $creditUser, string $license, int $width, int $height): void
    {
        $this->db->executeStatement(
            'UPDATE commons_photo SET state = :s, storage_bucket = :b, storage_prefix = :p, credit = :c,
                    credit_user = :u, license = :l, width = :w, height = :h, ready_at = NOW(), failed_reason = NULL
             WHERE file = :f',
            ['s' => CommonsPhotoState::Ready->value, 'b' => $bucket, 'p' => $prefix, 'c' => $credit,
                'u' => $creditUser, 'l' => $license, 'w' => $width, 'h' => $height, 'f' => $file],
        );
    }

    /**
     * Put a row back in the queue when nothing is going to happen otherwise.
     *
     * Two cases, both of which left a photo permanently absent before this
     * existed, because `claim()` refuses any row that already exists.
     *
     * A `failed` row is a transient problem that nobody would ever retry: one
     * refused download and the photo never appears again. That happened on the
     * first real run (2026-08-25).
     *
     * A long-`pending` row is worse, because it is silent. A message can be
     * lost: the worker dies mid-handler, or delivery retries run out and it
     * lands on the failure transport. The row then says `pending` with no job
     * behind it, and every rider who opens that POI watches a spinner for 45
     * seconds and gets nothing, forever. So a claim older than the grace period
     * is treated as abandoned.
     *
     * `unusable` is deliberately not eligible either way: that verdict is about
     * the file, and it does not change by asking again.
     *
     * True only for the caller that actually re-queued it, so one dispatch.
     */
    public function retry(string $file, int $maxAttempts, string $staleAfter = '15 minutes'): bool
    {
        return 1 === $this->db->executeStatement(
            'UPDATE commons_photo
                SET state = :pending, failed_reason = NULL, attempts = attempts + 1, requested_at = NOW()
              WHERE file = :f
                AND attempts < :max
                AND (state = :failed
                     OR (state = :pending AND requested_at < NOW() - CAST(:stale AS interval)))',
            ['pending' => CommonsPhotoState::Pending->value, 'failed' => CommonsPhotoState::Failed->value,
                'f' => $file, 'max' => $maxAttempts, 'stale' => $staleAfter],
        );
    }

    /** A verdict about the file. Terminal: asking again would get the same answer. */
    public function markUnusable(string $file, string $reason): void
    {
        $this->settle($file, CommonsPhotoState::Unusable, $reason);
    }

    /** Our problem, not the file's. Left retryable on purpose. */
    public function markFailed(string $file, string $reason): void
    {
        $this->settle($file, CommonsPhotoState::Failed, $reason);
    }

    private function settle(string $file, CommonsPhotoState $state, string $reason): void
    {
        $this->db->executeStatement(
            'UPDATE commons_photo SET state = :s, failed_reason = :r, attempts = attempts + 1 WHERE file = :f',
            ['s' => $state->value, 'r' => substr($reason, 0, 64), 'f' => $file],
        );
    }
}
