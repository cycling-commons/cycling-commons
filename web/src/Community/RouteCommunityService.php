<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Route community loop: snapshot, ride (and verify flip), vote, suggestion.
 *
 * @see docs/specs/route-domain.md §6
 *
 * @api
 */
final class RouteCommunityService
{
    private const int NOTE_MAX_LENGTH = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly SettingsProviderInterface $settings,
        private readonly RateLimiterFactoryInterface $routeSuggestLimiter,
    ) {
    }

    public function rideVerifyThreshold(): int
    {
        return $this->settings->get(SettingsRegistry::ROUTE_RIDE_VERIFY_THRESHOLD);
    }

    /**
     * @return array{state: string, rideCount: int, threshold: int, iRode: bool, voteCount: int, iVotedThisSeason: bool}
     */
    public function snapshot(RecommendedRoute $route, User $user, Season $season): array
    {
        $routeId = (int) $route->getId();
        $userId = $user->getId();

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
            'threshold' => $this->rideVerifyThreshold(),
            'iRode' => $iRode,
            'voteCount' => $voteCount,
            'iVotedThisSeason' => $iVoted,
        ];
    }

    /**
     * Idempotent "I rode this". At the independent-rider threshold, flips unverified → verified.
     */
    public function recordRide(RecommendedRoute $route, User $user, BikeType $bike): void
    {
        $routeId = (int) $route->getId();

        $already = (bool) $this->db->fetchOne(
            'SELECT 1 FROM route_ride WHERE route_id = :r AND user_id = :u',
            ['r' => $routeId, 'u' => $user->getId()],
        );
        if ($already) {
            return;
        }

        $this->em->persist(new RouteRide($routeId, $user->getId(), $bike));

        if (ItemState::Unverified === $route->getState()) {
            $independent = (int) $this->db->fetchOne(
                'SELECT COUNT(DISTINCT user_id) FROM route_ride WHERE route_id = :r AND user_id <> :p',
                ['r' => $routeId, 'p' => $route->getProposedBy() ?? -1],
            );
            $isProposer = null !== $route->getProposedBy() && $route->getProposedBy() === $user->getId();
            $independentAfter = $independent + ($isProposer ? 0 : 1);

            if ($independentAfter >= $this->rideVerifyThreshold()) {
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

    /** Idempotent per (route, user, season). */
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
     * Moderated correction (docs/specs/route-domain.md §10). Validate before the limiter.
     *
     * `$note` is the rider's word to the curator and is never route content:
     * the public "Note for riders" is one of the fields `$changes` can carry.
     *
     * @param list<array{start: float, end: float}>|null        $segments located stretches (docs/specs/route-domain.md §7)
     * @param array<string, array{was: mixed, now: mixed}>|null $changes  what a detail correction asks for (§7.1)
     *
     * @throws TooManyRequestsHttpException over the daily suggestion limit
     * @throws \InvalidArgumentException    if the trimmed note exceeds 2000 characters
     */
    public function recordSuggestion(RecommendedRoute $route, User $user, RouteSuggestionReason $reason, ?string $note, ?array $segments = null, ?array $changes = null): void
    {
        $trimmed = null !== $note ? trim($note) : null;
        if (null !== $trimmed && mb_strlen($trimmed) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException('route.error.note_too_long');
        }

        if (!$this->routeSuggestLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }
        $this->em->persist(new RouteSuggestion((int) $route->getId(), $user->getId(), $reason, '' !== $trimmed ? $trimmed : null, $segments, $changes));
        $this->em->flush();
    }
}
