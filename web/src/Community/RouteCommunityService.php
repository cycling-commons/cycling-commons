<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Season;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly int $rideVerifyThreshold,
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
}
