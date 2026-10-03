<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Vote\BallotRules;
use PHPUnit\Framework\TestCase;

/**
 * The ballot's numbers are the spec's (route-domain.md §8c), and the vote
 * count and the points table stay one rule: changing the ballot is one edit in
 * BallotRules.
 */
final class BallotRulesTest extends TestCase
{
    public function testTheRulesAreTheSpecsNumbers(): void
    {
        self::assertSame(5, BallotRules::VOTES_PER_LIST);
        self::assertSame([1 => 15, 2 => 10, 3 => 7, 4 => 4, 5 => 2], BallotRules::POINTS_BY_SLOT);
        self::assertSame(14, BallotRules::MIN_ACCOUNT_AGE_DAYS);
        self::assertSame(5, BallotRules::RANKING_THRESHOLD);
        self::assertSame(5, BallotRules::HANDICAP_TOP);
        self::assertSame(0.75, BallotRules::HANDICAP_QUARTERS / BallotRules::FULL_QUARTERS);
        self::assertSame(BallotRules::FULL_QUARTERS, BallotRules::CLOSE_CALL_QUARTERS);
    }

    public function testEveryVoteHasItsPointsAndNoMore(): void
    {
        self::assertCount(BallotRules::VOTES_PER_LIST, BallotRules::POINTS_BY_SLOT);
        self::assertSame(range(1, BallotRules::VOTES_PER_LIST), array_keys(BallotRules::POINTS_BY_SLOT));
    }

    public function testAHigherChoiceIsNeverWorthLess(): void
    {
        $points = array_values(BallotRules::POINTS_BY_SLOT);
        $sorted = $points;
        rsort($sorted);
        self::assertSame($sorted, $points);
        self::assertGreaterThan(0, min($points));
    }

    public function testTheDatabaseHasRoomForTheBallot(): void
    {
        self::assertLessThanOrEqual(BallotRules::MAX_SLOTS, BallotRules::VOTES_PER_LIST);
    }
}
