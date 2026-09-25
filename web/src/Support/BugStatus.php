<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * Where a bug report has got to.
 *
 * Two of these are promises to the reporter and the rest are internal
 * bookkeeping, so the set is kept short: a status list nobody updates is worse
 * than no status list, because it says "fixed" about things that are not.
 *
 * `New` and `Declined` are the only two a report can be created in or closed
 * to without work happening; everything between them means a curator has read
 * it and said something about it.
 *
 * @see docs/specs/contact-and-support.md §5
 *
 * @api
 */
enum BugStatus: string
{
    /** Filed, nobody has looked yet. */
    case New = 'new';
    /** Read, but nobody has confirmed it is real yet: check the data or the site first. */
    case NeedsValidation = 'needs_validation';
    /** Read, confirmed, and queued to fix. */
    case Planned = 'planned';
    /** Somebody is on it now. */
    case InProgress = 'in_progress';
    /** Believed fixed, waiting to be checked in the real site. */
    case NeedsTesting = 'needs_testing';
    /** Fixed and checked. */
    case Resolved = 'resolved';
    /** Read, and we are not going to change it. Always with a reason. */
    case Declined = 'declined';

    /** @return list<self> desk filter order */
    public static function all(): array
    {
        return [self::New, self::NeedsValidation, self::Planned, self::InProgress, self::NeedsTesting, self::Resolved, self::Declined];
    }

    /** @return list<self> still costing somebody something */
    public static function open(): array
    {
        return [self::New, self::NeedsValidation, self::Planned, self::InProgress, self::NeedsTesting];
    }

    public function isOpen(): bool
    {
        return \in_array($this, self::open(), true);
    }

    /**
     * Does reaching this status owe the reporter a message?
     *
     * A rider who took the trouble to report something is owed the outcome, and
     * only the outcome. Being told it moved from Planned to In progress is
     * noise, and noise is what teaches people to ignore the next mail.
     */
    public function notifiesReporter(): bool
    {
        return self::Resolved === $this || self::Declined === $this;
    }
}
