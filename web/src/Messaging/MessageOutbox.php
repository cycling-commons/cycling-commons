<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\UserMessage;

/**
 * Request-scoped queue of messages to email after the request/command ends.
 *
 * @see docs/specs/moderation-and-contribution.md §7.8
 *
 * @api
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
     * @return list<UserMessage>
     */
    public function drain(): array
    {
        $out = $this->queued;
        $this->queued = [];

        return $out;
    }
}
