<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\UserMessage;

/**
 * Messages waiting to be emailed, held until the request (or command) is over.
 *
 * The reason this exists rather than `MessageService` mailing directly:
 * **`sendSystem()` runs inside the moderation decision's transaction.** Sending
 * there would put mail on the wire for a decision that can still roll back — a
 * rider told their climb was approved when nothing was. Worse, it would put an
 * SMTP round trip inside a database transaction, holding row locks for as long
 * as the mail server feels like taking.
 *
 * So sends are collected here and flushed by
 * {@see \App\EventSubscriber\MessageMailSubscriber} on kernel/console
 * `terminate` — after the response has gone to the browser, and after the
 * transaction has either committed or not. The subscriber re-checks that each
 * message still exists before mailing it, which is what makes a rolled-back
 * decision send nothing.
 *
 * Request-scoped by construction: the container gives each request its own
 * instance, so nothing leaks between them.
 *
 * @api Filled by MessageService, drained by MessageMailSubscriber.
 */
final class MessageOutbox
{
    /** @var list<UserMessage> */
    private array $queued = [];

    public function queue(UserMessage $message): void
    {
        $this->queued[] = $message;
    }

    /**
     * Hands over everything queued and empties the queue, so a second flush in
     * the same process (a console command that dispatches twice) cannot send
     * the same mail again.
     *
     * @return list<UserMessage>
     */
    public function drain(): array
    {
        $out = $this->queued;
        $this->queued = [];

        return $out;
    }
}
