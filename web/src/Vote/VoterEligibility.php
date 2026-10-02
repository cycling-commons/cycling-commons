<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Who may vote: a confirmed email, an account at least 14 days old, and one
 * thing done on the site. With few voters one fake account would decide a
 * list (route-domain.md §8c).
 *
 * "One thing done" is something a person on the ground or a curator stood
 * behind, or still has pending: a route ride, a drawer confirmation of a
 * place, a submission that is pending, needs info or approved, a route
 * proposal that is submitted, unverified, verified or retired, an applied
 * route correction. A submitter's own answer on a form, and anything
 * rejected, withdrawn or trashed, does not count.
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
final class VoterEligibility
{
    public const string EMAIL = 'email';
    public const string AGE = 'age';
    public const string ACTIVITY = 'activity';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<string> what is still missing, in this order; empty means the rider may vote */
    public function missing(User $user): array
    {
        $missing = [];
        if (!$user->isEmailVerified()) {
            $missing[] = self::EMAIL;
        }
        $from = $this->votesFrom($user);
        if (null === $from || $from > $this->clock->now()) {
            $missing[] = self::AGE;
        }
        if (!$this->hasDoneSomething((int) $user->getId())) {
            $missing[] = self::ACTIVITY;
        }

        return $missing;
    }

    /** The moment the account is old enough. */
    public function votesFrom(User $user): ?\DateTimeImmutable
    {
        return $user->getCreatedAt()?->modify(sprintf('+%d days', BallotRules::MIN_ACCOUNT_AGE_DAYS));
    }

    private function hasDoneSomething(int $userId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM route_ride WHERE user_id = :u)
                 OR EXISTS (SELECT 1 FROM item_confirmation WHERE user_id = :u AND source = 'drawer')
                 OR EXISTS (SELECT 1 FROM submission WHERE user_id = :u AND status IN ('pending', 'needs_info', 'approved'))
                 OR EXISTS (SELECT 1 FROM recommended_route WHERE proposed_by = :u AND state IN ('submitted', 'unverified', 'verified', 'retired'))
                 OR EXISTS (SELECT 1 FROM route_suggestion WHERE user_id = :u AND status = 'done')",
            ['u' => $userId],
        );
    }
}
