<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

/**
 * How the bugs desk is ordered.
 *
 * Four, and only four. A desk with a sortable header per column looks powerful
 * and is used one way in practice: "what is new" or "what is worst". The other
 * two exist because both have a real opposite: oldest-first finds what has been
 * waiting, and lowest-priority-first is how somebody clears a backlog of small
 * things in an afternoon.
 *
 * Newest stays the default. A desk that reorders itself by severity hides the
 * report that arrived thirty seconds ago, which is the one most likely to be
 * about something that just broke.
 *
 * @see docs/specs/contact-and-support.md §9
 *
 * @api
 */
enum BugSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Worst = 'worst';
    case Smallest = 'smallest';

    public function label(): string
    {
        return 'support.bugs.sort_'.$this->value;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? self::Newest;
    }

    /**
     * Does this order lead with severity?
     *
     * The repository needs to know, because ordering by severity means adding a
     * CASE expression to the select, and there is no reason to pay for it when
     * the answer is "newest first".
     */
    public function bySeverity(): bool
    {
        return self::Worst === $this || self::Smallest === $this;
    }

    /** ASC puts critical first, because {@see BugSeverity::weight()} counts up from it. */
    public function severityDirection(): string
    {
        return self::Worst === $this ? 'ASC' : 'DESC';
    }

    /**
     * Newest within a severity band, always.
     *
     * Even in "low priority first" the tie-break is recency: a curator working
     * through cosmetic bugs still wants this week's before last spring's.
     */
    public function dateDirection(): string
    {
        return self::Oldest === $this ? 'ASC' : 'DESC';
    }
}
