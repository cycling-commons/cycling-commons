<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Commons;

use Doctrine\DBAL\Connection;

/**
 * The P18 cache: which Commons file a Wikidata item names, if any.
 *
 * `answered = TRUE` with `file IS NULL` is the important row. It means we asked
 * and the item has no image, which is an answer worth keeping: measured on
 * 2026-08-24 over a 600-item sample of our own scenic rows, 67 percent have no
 * P18, and without this every one of them would be re-asked on every drawer
 * open.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final readonly class WikidataImageRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** True only for the caller that created the row, which is the one that asks Wikidata. */
    public function claim(string $qid): bool
    {
        return 1 === $this->db->executeStatement(
            'INSERT INTO wikidata_image (qid, answered, checked_at) VALUES (:q, FALSE, NOW()) ON CONFLICT (qid) DO NOTHING',
            ['q' => $qid],
        );
    }

    /** @return array{qid: string, file: ?string, answered: bool}|null */
    public function find(string $qid): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT qid, file, answered FROM wikidata_image WHERE qid = :q',
            ['q' => $qid],
        );
        if (false === $row) {
            return null;
        }

        return ['qid' => (string) $row['qid'], 'file' => $row['file'], 'answered' => (bool) $row['answered']];
    }

    /** Wikidata replied. A null file is the answer "this item has no image". */
    public function record(string $qid, ?string $file): void
    {
        $this->db->executeStatement(
            'UPDATE wikidata_image SET file = :f, answered = TRUE, checked_at = NOW() WHERE qid = :q',
            ['f' => $file, 'q' => $qid],
        );
    }

    /**
     * Wikidata did not reply. Drop the unanswered row so a later visit asks
     * again, rather than leaving a claim nobody will ever fulfil and a POI that
     * spins until the poll gives up, every time, forever.
     */
    public function release(string $qid): void
    {
        $this->db->executeStatement(
            'DELETE FROM wikidata_image WHERE qid = :q AND answered = FALSE',
            ['q' => $qid],
        );
    }
}
