<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * Where a contact message has got to.
 *
 * @see docs/specs/contact-and-support.md §4
 *
 * @api
 */
enum ContactStatus: string
{
    /** Arrived, nobody has read it. */
    case New = 'new';
    /** Read, and somebody is dealing with it. */
    case Open = 'open';
    /** Answered. */
    case Answered = 'answered';
    /** Spam, or nothing to answer. Closed without a reply, on purpose. */
    case Closed = 'closed';

    /** @return list<self> desk filter order */
    public static function all(): array
    {
        return [self::New, self::Open, self::Answered, self::Closed];
    }

    public function isOpen(): bool
    {
        return self::New === $this || self::Open === $this;
    }
}
