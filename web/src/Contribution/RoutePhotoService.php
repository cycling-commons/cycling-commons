<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Entity\User;
use App\Media\MediaClaimService;
use App\Moderation\OutOfScopeException;
use App\Moderation\RouteModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Photos for a live recommended route, from `/propose-route?route=<id>`.
 *
 * A route has no rider edit form (route-domain.md §1), so a rider's photos
 * arrive the way every other rider word on a route does: as a correction,
 * reason `photo`, on the Routes desk. Marking it done attaches the photos,
 * dismissing it rejects them (RouteModerationService::resolveSuggestion()).
 * A curator's own photos are applied at once, the rule a curator's own place
 * edit follows (moderation-and-contribution.md §1.6), within their areas.
 *
 * @see docs/specs/route-domain.md §4.5
 * @see docs/specs/photo-uploads.md §5i
 *
 * @api
 */
final class RoutePhotoService
{
    private const int NOTE_MAX_LENGTH = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaClaimService $claims,
        private readonly RouteModerationService $moderation,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly RateLimiterFactoryInterface $routeSuggestLimiter,
    ) {
    }

    /**
     * @return array{suggestion: RouteSuggestion, applied: bool}
     *
     * @throws TooManyRequestsHttpException over the daily suggestion limit
     * @throws \InvalidArgumentException    validation failure (message = translation key)
     */
    public function submit(RecommendedRoute $route, User $by, mixed $rawMediaIds, mixed $rawAlts, ?string $note): array
    {
        if (!\in_array($route->getState(), ItemState::SERVED, true)) {
            throw new \InvalidArgumentException('propose_route.photos.error.route_gone');
        }
        if (!self::hasIds($rawMediaIds)) {
            throw new \InvalidArgumentException('propose_route.photos.error.none');
        }
        $note = null === $note ? null : trim($note);
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException('propose_route.error.note_too_long');
        }
        if (!$this->routeSuggestLimiter->create('user-'.(string) $by->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }

        $suggestion = $this->em->wrapInTransaction(function () use ($route, $by, $rawMediaIds, $rawAlts, $note): RouteSuggestion {
            $suggestion = new RouteSuggestion((int) $route->getId(), (int) $by->getId(), RouteSuggestionReason::Photo, '' === $note ? null : $note);
            $this->em->persist($suggestion);
            $this->em->flush();
            try {
                $this->claims->claimForRoute($rawMediaIds, $by, $route, $suggestion, $rawAlts);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('contribute.error.media_invalid');
            }
            $this->em->flush();

            return $suggestion;
        });

        $applied = false;
        if (\in_array('ROLE_CURATOR', $this->roleHierarchy->getReachableRoleNames($by->getRoles()), true)) {
            try {
                $this->moderation->resolveSuggestion((int) $suggestion->getId(), RouteSuggestionStatus::Done, $by);
                $applied = true;
            } catch (OutOfScopeException) {
                // Outside the curator's areas: it waits on the desk like anybody's.
            }
        }

        return ['suggestion' => $suggestion, 'applied' => $applied];
    }

    private static function hasIds(mixed $raw): bool
    {
        if (\is_array($raw)) {
            return [] !== $raw;
        }
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $decoded = json_decode($raw, true);

        return !\is_array($decoded) || [] !== $decoded;
    }
}
