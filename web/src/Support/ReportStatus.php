<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * Where a content report has got to.
 *
 * Three outcomes, not two, because "we agree and acted" and "there is nothing
 * here to act on" are the same to a reporter and completely different to the
 * author: only the first restricted somebody's content, and only the first
 * earns the statement of reasons DSA Article 17 requires.
 *
 * @see docs/specs/content-reports.md §4
 *
 * @api
 */
enum ReportStatus: string
{
    case Open = 'open';
    /**
     * A curator has taken it up and is on it: still open, nobody has been
     * answered, no clock has stopped (owner 2026-09-08: the list had no way
     * to say somebody is working on it).
     */
    case InProgress = 'in_progress';
    /** Acted on: something was removed or restricted. The author must be told. */
    case Upheld = 'upheld';
    /** Looked at, nothing was wrong. The reporter is told why. */
    case Rejected = 'rejected';
    /** Nothing to act on: already gone, or never there. Nobody is accused. */
    case Moot = 'moot';

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /** The four a curator can choose. Open is the state a report starts in. */
    public function label(): string
    {
        return 'report.status.'.$this->value;
    }

    public function isDecided(): bool
    {
        return !\in_array($this, self::open(), true);
    }

    /**
     * The states a report is still waiting in. Both count on the desk badge
     * and both show under the desk's default filter.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Open, self::InProgress];
    }

    /** Only an upheld report restricted somebody, so only it owes a statement of reasons. */
    public function owesStatementOfReasons(): bool
    {
        return self::Upheld === $this;
    }
}
