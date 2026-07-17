<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionStatus;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Service\AdminActionLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ONLY write-path for route moderation - purpose-built, no reuse of
 * ModerationService. Every transition is transactional and appends
 * route_change_history. The region cap is enforced on approve. Decision
 * outcomes message the proposer, skipped for imported routes
 * (`proposedBy === null`).
 *
 * @see docs/specs/route-domain.md §5.1
 * @see docs/specs/moderation-and-contribution.md §7.2
 *
 * @api Called by RouteModerateController.
 */
final class RouteModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $regionActiveCap,
        private readonly MessageService $messages,
        private readonly AdminActionLogger $adminLog,
        private readonly ModerationScopeProvider $scopeProvider,
    ) {
    }

    private function assertInScope(User $curator, ?int $regionId): void
    {
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $regionId)) {
            throw new OutOfScopeException('Route outside the curator\'s assigned areas.');
        }
    }

    public function approve(int $routeId, User $curator): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $curator): RecommendedRoute {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            if (ItemState::Submitted !== $route->getState()) {
                throw new \LogicException('Only a submitted route can be approved.');
            }
            // Known TOCTOU: this COUNT(*) isn't locked, so two concurrent approve()
            // calls at cap-1 could both pass and both commit, briefly exceeding the
            // cap. Accepted tradeoff, not a bug: few curators, soft editorial cap,
            // recoverable via retire(); not worth locking for this workflow.
            if ($this->activeCountForRegion($route->getRegionId()) >= $this->regionActiveCap) {
                throw new RegionFullException(sprintf('Region %s is at the active-route cap.', $route->getRegionId() ?? 'none'));
            }
            $this->transition($route, ItemState::Unverified, $curator);
            $this->notifyProposer($route, UserMessageKind::RouteApproved, null);

            return $route;
        });
    }

    public function reject(int $routeId, User $curator, ?string $note): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $curator, $note): RecommendedRoute {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            if (ItemState::Submitted !== $route->getState()) {
                throw new \LogicException('Only a submitted route can be rejected.');
            }
            $this->transition($route, ItemState::Rejected, $curator, $note);
            $this->notifyProposer($route, UserMessageKind::RouteRejected, $note);

            return $route;
        });
    }

    public function retire(int $routeId, User $curator, string $note): RecommendedRoute
    {
        if ('' === trim($note)) {
            throw new \InvalidArgumentException('Retiring a route requires a note.');
        }

        return $this->em->wrapInTransaction(function () use ($routeId, $curator, $note): RecommendedRoute {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            if (!\in_array($route->getState(), ItemState::SERVED, true)) {
                throw new \LogicException('Only an active (unverified/verified) route can be retired.');
            }
            $this->transition($route, ItemState::Retired, $curator, $note);
            $this->notifyProposer($route, UserMessageKind::RouteRetired, $note);

            return $route;
        });
    }

    /**
     * @param array<string, mixed> $changes field => proposed value; `name` is a
     *                                      pseudo-field (Route::name), all others attributes
     */
    public function editMetadata(int $routeId, array $changes, User $curator): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $changes, $curator): RecommendedRoute {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            $attributes = $route->getAttributes();

            foreach ($changes as $field => $new) {
                $current = 'name' === $field ? $route->getName() : ($attributes[$field] ?? null);
                if ($current === $new || (null === $new && null === ($current ?? null))) {
                    continue; // no-op: never snapshot an unchanged field
                }
                if ('name' === $field) {
                    $route->setName((string) $new);
                } else {
                    $attributes[$field] = $new;
                }
                $this->em->persist(new RouteChangeHistory((int) $route->getId(), (string) $field, $current, $new, $curator->getId()));
            }
            $route->setAttributes($attributes);

            return $route;
        });
    }

    public function resolveSuggestion(int $suggestionId, RouteSuggestionStatus $status, User $curator): RouteSuggestion
    {
        if (RouteSuggestionStatus::Pending === $status) {
            throw new \LogicException('Resolution must be done or dismissed.');
        }

        return $this->em->wrapInTransaction(function () use ($suggestionId, $status, $curator): RouteSuggestion {
            $s = $this->em->find(RouteSuggestion::class, $suggestionId);
            if (null === $s) {
                throw new \InvalidArgumentException(sprintf('Suggestion %d not found.', $suggestionId));
            }
            $route = $this->em->find(RecommendedRoute::class, $s->getRouteId());
            $this->assertInScope($curator, $route?->getRegionId());
            if (RouteSuggestionStatus::Pending !== $s->getStatus()) {
                throw new \LogicException('Suggestion already resolved.');
            }
            $s->resolve($status, $curator->getId());

            $routeName = $route?->getName() ?? sprintf('route-%d', $s->getRouteId());
            $kind = RouteSuggestionStatus::Done === $status ? UserMessageKind::CorrectionDone : UserMessageKind::CorrectionDismissed;
            $this->messages->sendSystem(
                $s->getUserId(), $kind,
                'correction', (int) $s->getId(), $routeName,
                'messages.body.'.$kind->value, ['%name%' => $routeName],
            );

            return $s;
        });
    }

    /**
     * Trash (M9): a hard, permanent delete of a route correction (any
     * status). Its `segments` are a JSON column on the row itself, so the
     * delete leaves no orphan segment data. Audited content-free FIRST
     * (AdminActionLogger::log() flushes its own row inside this transaction),
     * never a UserMessage - trashing never feeds spam.
     *
     * @see docs/specs/moderation-and-contribution.md §6
     */
    public function trashSuggestion(int $id, User $curator): void
    {
        $this->em->wrapInTransaction(function () use ($id, $curator): void {
            $s = $this->em->find(RouteSuggestion::class, $id);
            if (null === $s) {
                throw new \InvalidArgumentException(sprintf('Suggestion %d not found.', $id));
            }
            $route = $this->em->find(RecommendedRoute::class, $s->getRouteId());
            $this->assertInScope($curator, $route?->getRegionId());

            $this->adminLog->log($curator, TrashActions::TrashCorrection, null, sprintf('suggestion %d on route %d', $id, $s->getRouteId()));
            $this->em->remove($s);
        });
    }

    /**
     * Trash (M9): a hard, permanent delete of a route proposal, ONLY while
     * it is `submitted` or `rejected`. Never an active/served (unverified,
     * verified) or retired route (TrashBlockedException guardrail). Audited
     * content-free FIRST, never a UserMessage - trashing never feeds spam.
     *
     * @see docs/specs/moderation-and-contribution.md §6
     */
    public function trashProposal(int $routeId, User $curator): void
    {
        $this->em->wrapInTransaction(function () use ($routeId, $curator): void {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            if (!\in_array($route->getState(), [ItemState::Submitted, ItemState::Rejected], true)) {
                throw new TrashBlockedException(sprintf('Route %d is %s and cannot be trashed.', $routeId, $route->getState()->value));
            }

            // Content-free audit (M9): NO rider-authored text. A submitted
            // proposal's NAME is unvetted free text and must not land in the
            // immutable log (same rule as the submission path's enum-only note).
            $this->adminLog->log($curator, TrashActions::TrashRouteProposal, null, sprintf('route %d state=%s', $routeId, $route->getState()->value));
            $this->em->remove($route);
        });
    }

    /** The configured region active-route cap (%route.region_active_cap%). */
    public function regionCap(): int
    {
        return $this->regionActiveCap;
    }

    /** Active routes (SERVED) in a region; NULL region counted as its own bucket. */
    public function activeCountForRegion(?int $regionId): int
    {
        $qb = $this->em->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')->from('recommended_route')
            ->where('state IN (:states)')
            ->setParameter('states', array_map(static fn (ItemState $s): string => $s->value, ItemState::SERVED), \Doctrine\DBAL\ArrayParameterType::STRING);
        if (null === $regionId) {
            $qb->andWhere('region_id IS NULL');
        } else {
            $qb->andWhere('region_id = :region')->setParameter('region', $regionId);
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    private function transition(RecommendedRoute $route, ItemState $to, User $curator, ?string $note = null): void
    {
        $from = $route->getState();
        $route->setState($to);
        $this->em->persist(new RouteChangeHistory((int) $route->getId(), 'state', $from->value, $to->value, $curator->getId()));
        if (null !== $note && '' !== trim($note)) {
            $this->em->persist(new RouteChangeHistory((int) $route->getId(), 'decision_note', null, $note, $curator->getId()));
        }
    }

    /**
     * M2: rides the same transaction as the decision, atomic, and skipped
     * entirely for imported routes (`proposedBy === null`, no rider to tell).
     */
    private function notifyProposer(RecommendedRoute $route, UserMessageKind $kind, ?string $note): void
    {
        if (null === $route->getProposedBy()) {
            return;
        }
        $this->messages->sendSystem(
            $route->getProposedBy(), $kind,
            'route', (int) $route->getId(), $route->getName(),
            'messages.body.'.$kind->value, ['%name%' => $route->getName()],
            $note,
        );
    }

    private function load(int $routeId): RecommendedRoute
    {
        $route = $this->em->find(RecommendedRoute::class, $routeId);
        if (null === $route) {
            throw new \InvalidArgumentException(sprintf('Route %d not found.', $routeId));
        }

        return $route;
    }
}
