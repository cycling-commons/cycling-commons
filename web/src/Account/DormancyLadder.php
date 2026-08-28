<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

/**
 * When an unused account is warned, and when it is finally closed.
 *
 * The owner's schedule, 2026-08-28, and a **port** of the one already running
 * in a sibling BikeCoders product rather than a fresh design. Three of its
 * choices are load-bearing and were adopted deliberately:
 *
 * | months idle | what happens |
 * |---|---|
 * | 12 | first reminder |
 * | 22 | second reminder, ten months later |
 * | 23 | final notice, one month before |
 * | 24 | the account is deleted |
 *
 * **1. Each notice fires inside a one-month window, not "at or past".** A pass
 * selects accounts idle between N and N+1 months. An account that has been
 * dormant for three years therefore matches no window at all, gets no notice,
 * and is never deleted. That looks like a gap and is the opposite: it means
 * switching this on cannot mass-delete a backlog of old accounts, because
 * deletion requires notices that those accounts can no longer earn.
 *
 * **2. Deletion requires all three notices, not just the last one.** Recorded
 * as three separate timestamps rather than one "current stage", so the question
 * "were they actually told, three times?" has a real answer per rung.
 *
 * **3. Signing in clears all three**, so somebody who returns after the final
 * notice starts again from nothing.
 *
 * @see docs/specs/account-and-auth.md §6.5
 *
 * @api
 */
final class DormancyLadder
{
    /** `notice code => months of silence that earns it`, in order. */
    public const array NOTICES = [
        'm12' => 12,
        'm22' => 22,
        'm23_final' => 23,
    ];

    /**
     * How wide each notice's window is.
     *
     * One month, matched to a daily sweep: wide enough that a run missed for a
     * week still catches everybody, narrow enough that no account collects two
     * notices from one rung.
     */
    public const int WINDOW_MONTHS = 1;

    /** After this long with no sign-in, and all three notices sent, it goes. */
    public const int DELETE_AFTER_MONTHS = 24;

    /**
     * Which notice this account has earned right now, if any.
     *
     * Windowed: `$monthsIdle` must fall inside the rung's month, and the rung
     * must not already have been sent. Returns null for anything older than the
     * last window, which is what stops an ancient account being swept up.
     *
     * @param array<string, bool> $alreadySent notice code => has it gone out
     */
    public static function noticeDue(int $monthsIdle, array $alreadySent): ?string
    {
        foreach (self::NOTICES as $code => $months) {
            $inWindow = $monthsIdle >= $months && $monthsIdle < $months + self::WINDOW_MONTHS;
            if ($inWindow && true !== ($alreadySent[$code] ?? false)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * May this account be deleted now?
     *
     * Long enough **and** told three times. `/privacy` promises we write first
     * and give time; one missing notice means that promise was not kept, so the
     * account stays.
     *
     * @param array<string, bool> $alreadySent notice code => has it gone out
     */
    public static function isDeletable(int $monthsIdle, array $alreadySent): bool
    {
        if ($monthsIdle < self::DELETE_AFTER_MONTHS) {
            return false;
        }

        foreach (array_keys(self::NOTICES) as $code) {
            if (true !== ($alreadySent[$code] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
