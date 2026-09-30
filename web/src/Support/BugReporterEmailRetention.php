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
 * Clears a bug reporter's address 24 months after the outcome.
 *
 * The address is optional and exists for one thing: telling the reporter the
 * outcome (`BugStatus::notifiesReporter()`, Fixed or Not changing this). Once
 * the report is resolved or declined that mail has gone out, and 24 months
 * later, the period the privacy page gives mail (`privacy.retention_mail`),
 * the address goes. Only `reporter_email` is cleared: the report is project
 * data and the fix still helps everybody. A signed-in reporter hears the
 * outcome through their account, so `user_id` is not touched here.
 *
 * **When the outcome was given.** `GREATEST(updated_at, notified_at)`: the
 * status change stamps `updated_at` and the outcome mail stamps `notified_at`,
 * so the later of the two is never earlier than the latest decision. A report
 * reopened and decided again starts a fresh 24 months, although the reporter
 * is told only once. A report still open keeps its address however old it is,
 * because the reporter has not had their answer.
 *
 * Runs from `app:media:gc`, beside the other support retention sweeps.
 *
 * @see docs/specs/contact-and-support.md §5
 *
 * @api
 */
final class BugReporterEmailRetention
{
    public const int MONTHS = 24;

    /** Rows per UPDATE, so one run never holds a long lock on the bug desk's table. */
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
    public function purgeExpiredEmails(): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d months', self::MONTHS))->format('Y-m-d H:i:s');
        $answered = array_values(array_map(
            static fn (BugStatus $s): string => $s->value,
            array_filter(BugStatus::cases(), static fn (BugStatus $s): bool => $s->notifiesReporter()),
        ));

        $total = 0;
        do {
            $cleared = (int) $this->db->executeStatement(
                <<<'SQL'
                    UPDATE bug_report SET reporter_email = NULL
                    WHERE id IN (
                        SELECT b.id FROM bug_report b
                        WHERE b.reporter_email IS NOT NULL
                          AND b.status IN (:answered)
                          AND GREATEST(b.updated_at, b.notified_at) < :cutoff
                        LIMIT :batch
                    )
                    SQL,
                ['answered' => $answered, 'cutoff' => $cutoff, 'batch' => self::BATCH],
                ['answered' => ArrayParameterType::STRING, 'batch' => ParameterType::INTEGER],
            );
            $total += $cleared;
        } while (self::BATCH === $cleared);

        $this->logger->info('Cleared expired bug reporter addresses.', ['count' => $total]);

        return $total;
    }
}
