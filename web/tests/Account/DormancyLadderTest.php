<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Account;

use App\Account\DormancyLadder;
use PHPUnit\Framework\TestCase;

/**
 * The schedule that decides when somebody loses their account.
 *
 * Owner's ladder, 2026-08-28, ported from a sibling BikeCoders product: warn at
 * 12 months, again at 22, a final notice at 23, delete at 24. Pure logic and no
 * database, because this is the part where an off-by-one costs somebody their
 * account.
 *
 * @see docs/specs/account-and-auth.md §6.5
 */
final class DormancyLadderTest extends TestCase
{
    private const array NONE_SENT = ['m12' => false, 'm22' => false, 'm23_final' => false];
    private const array ALL_SENT = ['m12' => true, 'm22' => true, 'm23_final' => true];

    public function testAnActiveAccountIsNeverWarned(): void
    {
        foreach ([0, 1, 6, 11] as $months) {
            self::assertNull(DormancyLadder::noticeDue($months, self::NONE_SENT), $months.' months is not dormant');
        }
    }

    public function testTheThreeRungsFireOnTheirMonths(): void
    {
        self::assertSame('m12', DormancyLadder::noticeDue(12, self::NONE_SENT));
        self::assertSame('m22', DormancyLadder::noticeDue(22, ['m12' => true] + self::NONE_SENT));
        self::assertSame('m23_final', DormancyLadder::noticeDue(23, ['m12' => true, 'm22' => true] + self::NONE_SENT));
    }

    /** Between rungs there is nothing to send; the clock just runs. */
    public function testNothingIsSentBetweenRungs(): void
    {
        foreach ([13, 17, 21] as $months) {
            self::assertNull(
                DormancyLadder::noticeDue($months, ['m12' => true] + self::NONE_SENT),
                $months.' months falls between windows',
            );
        }
    }

    /** A daily command must not re-send yesterday's warning every day. */
    public function testTheSameNoticeIsNeverSentTwice(): void
    {
        self::assertNull(DormancyLadder::noticeDue(12, ['m12' => true] + self::NONE_SENT));
        self::assertNull(DormancyLadder::noticeDue(22, ['m22' => true] + self::NONE_SENT));
        self::assertNull(DormancyLadder::noticeDue(23, ['m23_final' => true] + self::NONE_SENT));
    }

    /**
     * The safety property that makes switching this on survivable.
     *
     * Each notice fires only inside its own month. An account already dormant
     * for years when the sweep first runs is past every window, earns no
     * notice, and therefore can never satisfy the deletion condition. Turning
     * the feature on cannot clear a backlog of old accounts.
     */
    public function testAnAlreadyLongDormantAccountEarnsNothingAndSoIsNeverDeleted(): void
    {
        foreach ([25, 40, 120] as $months) {
            self::assertNull(DormancyLadder::noticeDue($months, self::NONE_SENT), $months.' months is past every window');
            self::assertFalse(DormancyLadder::isDeletable($months, self::NONE_SENT), $months.' months, never warned');
        }
    }

    public function testNothingIsDeletedBeforeTwentyFourMonths(): void
    {
        foreach ([12, 22, 23] as $months) {
            self::assertFalse(DormancyLadder::isDeletable($months, self::ALL_SENT), $months.' months is too early');
        }
    }

    /**
     * The promise on /privacy is that we write first and give time. One missing
     * notice means that promise was not kept, so the account stays.
     */
    public function testEveryOneOfTheThreeNoticesIsRequired(): void
    {
        foreach (['m12', 'm22', 'm23_final'] as $missing) {
            $sent = self::ALL_SENT;
            $sent[$missing] = false;
            self::assertFalse(DormancyLadder::isDeletable(24, $sent), $missing.' was never sent');
            self::assertFalse(DormancyLadder::isDeletable(120, $sent), 'ten years does not excuse a missing notice');
        }
    }

    public function testItDeletesOnlyWhenOldEnoughAndFullyWarned(): void
    {
        self::assertTrue(DormancyLadder::isDeletable(24, self::ALL_SENT));
        self::assertTrue(DormancyLadder::isDeletable(36, self::ALL_SENT));
    }

    /** Three warnings spread over a year, and the last two a month apart. */
    public function testTheShapeOfTheLadderItself(): void
    {
        self::assertSame(['m12' => 12, 'm22' => 22, 'm23_final' => 23], DormancyLadder::NOTICES);
        self::assertSame(24, DormancyLadder::DELETE_AFTER_MONTHS);
        self::assertLessThan(
            DormancyLadder::DELETE_AFTER_MONTHS,
            max(DormancyLadder::NOTICES),
            'the final warning must arrive before the deletion, not with it',
        );
    }
}
