<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes a content reporter's reply address 90 days after the decision.
 *
 * The address is kept only to answer the report (DSA Article 16(5)), so once
 * the report is decided and the window for a follow-up has passed it has no
 * purpose left (GDPR Art. 5(1)(e)). Only `reporter_contact` is cleared: the
 * claimant name on a copyright report is part of the claim the uploader was
 * shown in the statement of reasons, not a way to reach anybody, and the
 * report itself stays as the DSA record.
 *
 * Two kinds of report keep the address. One still waiting (open or in
 * progress, including one taken up again after a decision), because the
 * reporter has not had their answer. One about a photo under legal hold,
 * because the hold preserves the data around the material until the authority
 * it was reported to says otherwise (photo-uploads.md §6d).
 *
 * Runs from `app:media:gc`, next to the same sweep for photo requests.
 *
 * @see docs/specs/content-reports.md §10
 *
 * @api
 */
final class ReportContactRetention
{
    public const int DAYS = 90;

    /** Rows per UPDATE, so one run never holds a long lock on the desk's table. */
    private const int BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Clear every expired address. Idempotent: a cleared row no longer matches.
     *
     * @return int addresses cleared
     */
    public function purgeExpiredContacts(): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', self::DAYS))->format('Y-m-d H:i:s');
        $decided = array_values(array_map(
            static fn (ReportStatus $s): string => $s->value,
            array_filter(ReportStatus::cases(), static fn (ReportStatus $s): bool => $s->isDecided()),
        ));

        $total = 0;
        do {
            $cleared = (int) $this->db->executeStatement(
                <<<'SQL'
                    UPDATE content_report SET reporter_contact = NULL
                    WHERE id IN (
                        SELECT r.id FROM content_report r
                        WHERE r.reporter_contact IS NOT NULL
                          AND r.status IN (:decided)
                          AND r.decided_at < :cutoff
                          AND NOT (r.target_type = :photo AND EXISTS (
                              SELECT 1 FROM media_upload m
                              WHERE m.id::text = lower(r.target_id) AND m.escalated_at IS NOT NULL
                          ))
                        LIMIT :batch
                    )
                    SQL,
                ['decided' => $decided, 'cutoff' => $cutoff, 'photo' => ReportTarget::Photo->value, 'batch' => self::BATCH],
                ['decided' => ArrayParameterType::STRING, 'batch' => ParameterType::INTEGER],
            );
            $total += $cleared;
        } while (self::BATCH === $cleared);

        $this->logger->info('Cleared expired content report contacts.', ['count' => $total]);

        return $total;
    }
}
