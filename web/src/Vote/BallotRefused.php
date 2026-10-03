<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

/**
 * Why the ballot did not take a cast, a move, a remove or a submit. The reason is a stable
 * token; the page shows `vote.refused.<reason>`.
 *
 * @api
 */
final class BallotRefused extends \RuntimeException
{
    public const string VOTING_CLOSED = 'voting_closed';
    public const string NOT_ELIGIBLE = 'not_eligible';
    public const string NOT_VOTABLE = 'not_votable';
    public const string NOT_CANDIDATE = 'not_candidate';
    public const string BIKE_REQUIRED = 'bike_required';
    public const string BIKE_NOT_DECLARED = 'bike_not_declared';
    public const string BALLOT_FULL = 'ballot_full';
    public const string ALREADY_VOTED = 'already_voted';
    public const string RATE_LIMITED = 'rate_limited';
    public const string BALLOT_SUBMITTED = 'ballot_submitted';
    public const string BALLOT_INCOMPLETE = 'ballot_incomplete';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function messageKey(): string
    {
        return 'vote.refused.'.$this->reason;
    }
}
