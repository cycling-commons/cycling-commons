<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

/**
 * The season ballot's numbers. Constants, not admin settings: they are the
 * rules of a contest riders are taking part in, and a value moved mid-season
 * would change who could vote and who won after they acted.
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
final class BallotRules
{
    /** Votes per rider per list (region, season, category). The `season_vote_slot_range` CHECK says the same. */
    public const int VOTES_PER_LIST = 3;

    public const int MIN_ACCOUNT_AGE_DAYS = 14;

    /** Different voters a list needs before it is ranked. */
    public const int RANKING_THRESHOLD = 5;

    /**
     * Scores are kept in quarter votes, so x0.75 stays an integer: a vote is
     * worth 4, a handicapped vote 3.
     */
    public const int FULL_QUARTERS = 4;
    public const int HANDICAP_QUARTERS = 3;

    /** Last year's same-season places 1 to 3 carry the handicap. */
    public const int HANDICAP_TOP = 3;

    /** Less than one vote (four quarters) below the first item of a place shares it. */
    public const int CLOSE_CALL_QUARTERS = 4;

    /** A closed round is stored only after this, so a vote cast in its last second has committed. */
    public const string FREEZE_GRACE = '+1 hour';

    /** Rows a results list shows. */
    public const int TOP_N = 10;

    /** Candidates the ballot page lists before the rider's own votes and pick are added. */
    public const int BALLOT_CANDIDATES = 100;
}
