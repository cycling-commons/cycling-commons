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
 * Deletes a contact message 24 months after its matter ended.
 *
 * The privacy page promises that a conversation is kept while the matter is
 * open and deleted within 24 months of it ending (`privacy.retention_mail`).
 * The whole row goes, because all of it is the conversation: name, address,
 * body, page path and the curator's handling note.
 *
 * **When the matter ended.** A message has ended once a curator answered it or
 * closed it (`ContactStatus::isOpen()` is false). The end is read from
 * `updated_at`: every status change and every handling note stamps it, so it
 * is the moment the message was answered or closed, or later. A message
 * opened again is waiting once more and keeps no clock, and a message still
 * new or open is kept however old it is, because nobody has answered it.
 *
 * A contact message carries no legal hold of its own. A copyright claim or a
 * takedown that starts here is handled on the photo request or the content
 * report, and the hold lives there (photo-uploads.md §6d).
 *
 * Runs from `app:media:gc`, beside the reporter-contact sweeps.
 *
 * @see docs/specs/contact-and-support.md §4
 *
 * @api
 */
final class ContactMessageRetention
{
    public const int MONTHS = 24;

    /** Rows per DELETE, so one run never holds a long lock on the inbox table. */
    private const int BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Delete every expired message. Idempotent: a deleted row no longer matches.
     *
     * @return int messages deleted
     */
    public function purgeExpiredMessages(): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d months', self::MONTHS))->format('Y-m-d H:i:s');
        $ended = array_values(array_map(
            static fn (ContactStatus $s): string => $s->value,
            array_filter(ContactStatus::cases(), static fn (ContactStatus $s): bool => !$s->isOpen()),
        ));

        $total = 0;
        do {
            $deleted = (int) $this->db->executeStatement(
                <<<'SQL'
                    DELETE FROM contact_message
                    WHERE id IN (
                        SELECT m.id FROM contact_message m
                        WHERE m.status IN (:ended)
                          AND m.updated_at < :cutoff
                        LIMIT :batch
                    )
                    SQL,
                ['ended' => $ended, 'cutoff' => $cutoff, 'batch' => self::BATCH],
                ['ended' => ArrayParameterType::STRING, 'batch' => ParameterType::INTEGER],
            );
            $total += $deleted;
        } while (self::BATCH === $deleted);

        $this->logger->info('Deleted expired contact messages.', ['count' => $total]);

        return $total;
    }
}
