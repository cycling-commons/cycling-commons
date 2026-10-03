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
    /**
     * Votes per rider per list (region, season, category): one slot per
     * entry of {@see self::POINTS_BY_SLOT}, and BallotRulesTest keeps the two
     * the same. To change the ballot (say back to a top 3), change both here and
     * nothing else: the pages, the scoring SQL and the map read them. The
     * `season_vote_slot_range` CHECK only guards 1 to
     * {@see self::MAX_SLOTS}. Change it between seasons: a vote already
     * cast keeps its slot, so the open ballot would be scored on the new
     * points.
     */
    public const int VOTES_PER_LIST = 5;

    /** The `season_vote_slot_range` CHECK's upper bound, room for a longer ballot. */
    public const int MAX_SLOTS = 10;

    public const int MIN_ACCOUNT_AGE_DAYS = 14;

    /** Different voters a list needs before it is ranked. */
    public const int RANKING_THRESHOLD = 5;

    /**
     * A rider ranks their votes, a top 5 (owner 2026-10-03): the number one
     * is worth 15 points, then 10, 7, 4 and 2 (owner 2026-10-04). Keyed by
     * `season_vote.slot`, one entry per vote
     * ({@see self::VOTES_PER_LIST}), highest first.
     */
    public const array POINTS_BY_SLOT = [1 => 15, 2 => 10, 3 => 7, 4 => 4, 5 => 2];

    /**
     * Scores are kept in quarter points, so x0.75 stays an integer: a point
     * is worth 4, a handicapped point 3.
     */
    public const int FULL_QUARTERS = 4;
    public const int HANDICAP_QUARTERS = 3;

    /** Last year's same-season places 1 to 5 carry the handicap (owner 2026-10-04, as long as the ballot). */
    public const int HANDICAP_TOP = 5;

    /** Less than one point (four quarters) below the first item of a place shares it. */
    public const int CLOSE_CALL_QUARTERS = 4;

    /** A round's list is stored only after this past its start, so a vote cast in the ballot's last second has committed. */
    public const string FREEZE_GRACE = '+1 hour';

    /** Rows a results list shows. */
    public const int TOP_N = 10;

    /**
     * The most candidates the ballot page lists, A to Z. A guard, not a cut:
     * a region's list is far shorter (291 places to sleep at most, 2026-10-03),
     * and an alphabetical list cut short would hide the end of the alphabet.
     */
    public const int BALLOT_CANDIDATES = 2000;
}
