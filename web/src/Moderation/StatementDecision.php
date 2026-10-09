<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * What a statement of reasons tells somebody was decided (DSA Article
 * 17(3)(a)), and with it the scope that each decision has.
 *
 * @see docs/specs/content-reports.md §7
 *
 * @api
 */
enum StatementDecision: string
{
    /** A contribution, route, translation or file that was turned down and never went live. */
    case NotPublished = 'not_published';
    /** A contribution published without one or more of the photos sent with it. */
    case PhotosNotPublished = 'photos_not_published';
    /** Taken down: a photo removed, a contribution moved to the curators' bin. */
    case Removed = 'removed';
    /** A text a report was upheld on, which a curator then changed or took down. */
    case ChangedOrRemoved = 'changed_or_removed';
    /**
     * Out of sight until a curator has looked at it. No path builds it: an
     * urgent hide is explained once a curator has decided (HiddenThenRestored,
     * or Removed). Statements stored with it on a message row still read back.
     */
    case Hidden = 'hidden';
    /**
     * A photo hidden by our checks as soon as it was reported, before anybody
     * looked, and put back once a curator found the report did not hold up.
     * The hide restricted the photo for as long as it lasted, so it is owed a
     * statement; it goes with the photo's return, never at the hide, so that
     * nobody a report suspects is warned (content-reports.md §7).
     */
    case HiddenThenRestored = 'hidden_restored';
    /** A place or a route taken off the map; its history stays. */
    case Retired = 'retired';
    /** The account cannot be signed into until a date. */
    case AccountSuspended = 'account_suspended';
    /** The account is deleted. */
    case AccountRemoved = 'account_removed';

    public function isAboutAccount(): bool
    {
        return self::AccountSuspended === $this || self::AccountRemoved === $this;
    }

    /** The subject line and the heading of the statement. */
    public function headlineKey(): string
    {
        return 'dsa_statement.headline.'.$this->value;
    }
}
