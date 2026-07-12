<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\Entity\RouteRide;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\RouteVote;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\Season;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The route community loop (route-domain spec §7): reads a per-route snapshot
 * (counts + the current rider's state) and owns the three writes (ride + the
 * verification flip, vote, suggestion). Purpose-built — not the item pipeline
 * (spec D1). Reads are raw DBAL; writes go through the ORM.
 *
 * @api Consumed by RouteCommunityController (phase 3).
 */
final class RouteCommunityService
{
    private const int NOTE_MAX_LENGTH = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly int $rideVerifyThreshold,
        private readonly RateLimiterFactoryInterface $routeSuggestLimiter,
    ) {
    }

    /**
     * @return array{state: string, rideCount: int, threshold: int, iRode: bool, voteCount: int, iVotedThisSeason: bool}
     */
    public function snapshot(RecommendedRoute $route, User $user, Season $season): array
    {
        $routeId = (int) $route->getId();
        $userId = $user->getId();

        // rideCount is the INDEPENDENT distinct-rider count (excludes the
        // proposer, P3-D1) so the drawer's "N of X" equals the flip's own count.
        $rideCount = (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT user_id) FROM route_ride WHERE route_id = :r AND user_id <> :p',
            ['r' => $routeId, 'p' => $route->getProposedBy() ?? -1],
        );
        $iRode = (bool) $this->db->fetchOne(
            'SELECT 1 FROM route_ride WHERE route_id = :r AND user_id = :u',
            ['r' => $routeId, 'u' => $userId],
        );
        $voteCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM route_vote WHERE route_id = :r',
            ['r' => $routeId],
        );
        $iVoted = (bool) $this->db->fetchOne(
            'SELECT 1 FROM route_vote WHERE route_id = :r AND user_id = :u AND season = :s',
            ['r' => $routeId, 'u' => $userId, 's' => $season->value],
        );

        return [
            'state' => $route->getState()->value,
            'rideCount' => $rideCount,
            'threshold' => $this->rideVerifyThreshold,
            'iRode' => $iRode,
            'voteCount' => $voteCount,
            'iVotedThisSeason' => $iVoted,
        ];
    }

    /**
     * Records "I rode this" (idempotent per user/route) and, when a currently
     * `unverified` route reaches the independent-rider threshold (excluding the
     * proposer, P3-D1), flips it to `verified` — logging one history row
     * attributed to the tipping rider (P3-D2). All in one flush.
     */
    public function recordRide(RecommendedRoute $route, User $user, BikeType $bike): void
    {
        $routeId = (int) $route->getId();

        // Idempotent: a repeat click is a no-op (the UNIQUE index is the hard
        // guard; this pre-check keeps the EM open on the common repeat path).
        $already = (bool) $this->db->fetchOne(
            'SELECT 1 FROM route_ride WHERE route_id = :r AND user_id = :u',
            ['r' => $routeId, 'u' => $user->getId()],
        );
        if ($already) {
            return;
        }

        $this->em->persist(new RouteRide($routeId, $user->getId(), $bike));

        if (ItemState::Unverified === $route->getState()) {
            // Count includes the not-yet-flushed row via +1: the new rider is
            // independent (they have no prior ride and are not the proposer path
            // below), so add them to the persisted independent distinct count.
            $independent = (int) $this->db->fetchOne(
                'SELECT COUNT(DISTINCT user_id) FROM route_ride WHERE route_id = :r AND user_id <> :p',
                ['r' => $routeId, 'p' => $route->getProposedBy() ?? -1],
            );
            $isProposer = null !== $route->getProposedBy() && $route->getProposedBy() === $user->getId();
            $independentAfter = $independent + ($isProposer ? 0 : 1);

            if ($independentAfter >= $this->rideVerifyThreshold) {
                $route->setState(ItemState::Verified);
                $this->em->persist(new RouteChangeHistory(
                    $routeId,
                    'state',
                    ItemState::Unverified->value,
                    ItemState::Verified->value,
                    $user->getId(),
                ));
            }
        }

        $this->em->flush();
    }

    /** Records a typed seasonal vote; idempotent per (route, user, season). */
    public function recordVote(RecommendedRoute $route, User $user, Season $season, BikeType $bike): void
    {
        $already = (bool) $this->db->fetchOne(
            'SELECT 1 FROM route_vote WHERE route_id = :r AND user_id = :u AND season = :s',
            ['r' => (int) $route->getId(), 'u' => $user->getId(), 's' => $season->value],
        );
        if ($already) {
            return;
        }

        $this->em->persist(new RouteVote((int) $route->getId(), $user->getId(), $season, $bike));
        $this->em->flush();
    }

    /**
     * Records a moderated correction (route-domain spec §4.2). Rate-limited
     * (P3-D4) — the only self-unbounded community write; each pending row is a
     * curator task. `note` is stored raw and HTML-escaped on the desk render.
     *
     * @param list<array{start: float, end: float}>|null $segments located stretches (spec §16 S4)
     *
     * @throws TooManyRequestsHttpException over the daily suggestion limit
     * @throws \InvalidArgumentException    if the trimmed note exceeds 2000 characters (M11, route.error.note_too_long)
     */
    public function recordSuggestion(RecommendedRoute $route, User $user, RouteSuggestionReason $reason, ?string $note, ?array $segments = null): void
    {
        if (!$this->routeSuggestLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }

        $trimmed = null !== $note ? trim($note) : null;
        if (null !== $trimmed && mb_strlen($trimmed) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException('route.error.note_too_long');
        }
        $this->em->persist(new RouteSuggestion((int) $route->getId(), $user->getId(), $reason, '' !== $trimmed ? $trimmed : null, $segments));
        $this->em->flush();
    }
}
