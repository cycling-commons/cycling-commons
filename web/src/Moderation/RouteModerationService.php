<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemState;
use App\Catalog\RouteMetadata;
use App\Catalog\RouteSuggestionStatus;
use App\Entity\User;
use App\Media\MediaDecisionService;
use App\Media\MediaDisposalService;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Service\AdminActionLogger;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Write-path for route moderation. Region cap is enforced on approve.
 *
 * The photos sent with a proposal or a photo correction ride the same
 * decision (MediaDecisionService::applyToRoute(), photo-uploads.md §5i): no
 * second moderation mechanic for them.
 *
 * @see docs/specs/route-domain.md §5.1
 * @see docs/specs/moderation-and-contribution.md §7.2
 *
 * @api
 */
final class RouteModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsProviderInterface $settings,
        private readonly MessageService $messages,
        private readonly AdminActionLogger $adminLog,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly MediaDecisionService $mediaDecisions,
        private readonly MediaDisposalService $mediaDisposal,
    ) {
    }

    private function assertInScope(User $curator, ?int $regionId): void
    {
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $regionId)) {
            throw new OutOfScopeException('Route outside the curator\'s assigned areas.');
        }
    }

    /**
     * @param list<string> $rejectMediaIds photos the curator unticked (photo-uploads.md §5 per-photo decisions)
     */
    public function approve(int $routeId, User $curator, array $rejectMediaIds = []): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $curator, $rejectMediaIds): RecommendedRoute {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            if (ItemState::Submitted !== $route->getState()) {
                throw new \LogicException('Only a submitted route can be approved.');
            }
            // Known TOCTOU at cap-1: accepted for a soft editorial cap (docs/specs/route-domain.md §5.1).
            if ($this->activeCountForRegion($route->getRegionId()) >= $this->regionCap()) {
                throw new RegionFullException(sprintf('Region %s is at the active-route cap.', $route->getRegionId() ?? 'none'));
            }
            $this->transition($route, ItemState::Unverified, $curator);
            $this->decidePhotos($route, null, 'approve', $curator, null, $rejectMediaIds);
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
            $this->decidePhotos($route, null, 'reject', $curator, $note);
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
     * Apply a curator's metadata edit: every field is canonicalized through
     * {@see RouteMetadata::canonical()}, so a value a curator sets is stored in
     * the same shape as one the proposer set, and a field sent empty clears the
     * attribute instead of storing a blank. Each real change is snapshotted in
     * `route_change_history` with the curator as its author.
     *
     * `$author` credits the rider whose correction this is, not the curator who
     * approved it, the way an item's history credits its submitter
     * (moderation-and-contribution.md §4.1); `$suggestionId` links the row back
     * to that correction. Both are null for a curator's own desk edit.
     *
     * @param array<string, mixed> $changes field => proposed value; {@see RouteMetadata::NAME_FIELD}
     *                                      is the pseudo-field for Route::name, all others attributes
     *
     * @throws OutOfScopeException       the route is outside the curator's areas
     * @throws \InvalidArgumentException a field outside the registry, or a blank route name
     */
    public function editMetadata(int $routeId, array $changes, User $curator, ?int $author = null, ?int $suggestionId = null): RecommendedRoute
    {
        $creditTo = $author ?? $curator->getId();

        return $this->em->wrapInTransaction(function () use ($routeId, $changes, $curator, $creditTo, $suggestionId): RecommendedRoute {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            $attributes = $route->getAttributes();

            foreach ($changes as $field => $raw) {
                if (RouteMetadata::NAME_FIELD === $field) {
                    $this->renameRoute($route, $raw, (int) $creditTo, $suggestionId);
                    continue;
                }
                // The registry is the whole editable surface: nothing else reaches `attributes`.
                if (!\in_array($field, RouteMetadata::ATTRIBUTE_FIELDS, true)) {
                    throw new \InvalidArgumentException(sprintf('"%s" is not an editable route field.', $field));
                }
                $current = $attributes[$field] ?? null;
                $new = RouteMetadata::canonical($field, $raw);
                if (RouteMetadata::isSame($current, $new)) {
                    continue; // no-op: never snapshot an unchanged field
                }
                if (null === $new) {
                    unset($attributes[$field]); // cleared: an absent key, never a blank
                } else {
                    $attributes[$field] = $new;
                }
                $this->em->persist(new RouteChangeHistory((int) $route->getId(), $field, $current, $new, (int) $creditTo, $suggestionId));
            }
            $route->setAttributes($attributes);

            return $route;
        });
    }

    /** History files a rename under `name`, the column it changes. */
    private function renameRoute(RecommendedRoute $route, mixed $raw, int $creditTo, ?int $suggestionId): void
    {
        $name = \is_string($raw) ? trim($raw) : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('A route keeps a name.');
        }
        if ($name === $route->getName()) {
            return;
        }
        $this->em->persist(new RouteChangeHistory((int) $route->getId(), 'name', $route->getName(), $name, $creditTo, $suggestionId));
        $route->setName($name);
    }

    /**
     * @param list<string> $rejectMediaIds photos of a photo correction the curator unticked
     */
    public function resolveSuggestion(int $suggestionId, RouteSuggestionStatus $status, User $curator, array $rejectMediaIds = []): RouteSuggestion
    {
        if (RouteSuggestionStatus::Pending === $status) {
            throw new \LogicException('Resolution must be done or dismissed.');
        }

        return $this->em->wrapInTransaction(function () use ($suggestionId, $status, $curator, $rejectMediaIds): RouteSuggestion {
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
            if (null !== $route) {
                $this->decidePhotos($route, (int) $s->getId(), $status->value, $curator, null, $rejectMediaIds);
                $this->applyMetadata($route, $s, $status, $curator);
            }
            // A curator's own correction applied at once owes nobody a message.
            if ($s->getUserId() === $curator->getId()) {
                return $s;
            }

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
     * Hard-delete a route correction in any status.
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
            $this->mediaDisposal->purgeForRoute($s->getRouteId(), $id);
            $this->em->remove($s);
        });
    }

    /**
     * Hard-delete a proposal only while submitted or rejected.
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

            // Content-free: a submitted name is unvetted free text (docs/specs/moderation-and-contribution.md §6).
            $this->adminLog->log($curator, TrashActions::TrashRouteProposal, null, sprintf('route %d state=%s', $routeId, $route->getState()->value));
            $this->mediaDisposal->purgeForRoute($routeId, null);
            $this->em->remove($route);
        });
    }

    /** The configured region active-route cap (admin-editable; system-configuration.md §2). */
    public function regionCap(): int
    {
        return $this->settings->get(SettingsRegistry::ROUTE_REGION_ACTIVE_CAP);
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

    /**
     * Apply a route decision to its photos and record the gallery change in
     * the route's own history, the way an item's `change_history` records it.
     *
     * @param list<string> $rejectMediaIds
     */
    private function decidePhotos(RecommendedRoute $route, ?int $suggestionId, string $decision, User $curator, ?string $note, array $rejectMediaIds = []): void
    {
        $change = $this->mediaDecisions->applyToRoute($route, $suggestionId, $decision, $curator, $note, $rejectMediaIds);
        if (null !== $change) {
            $this->em->persist(new RouteChangeHistory((int) $route->getId(), 'photos', $change['old'], $change['new'], $curator->getId()));
        }
    }

    /**
     * A `metadata` correction marked done lands its proposed values on the
     * route through the same intake gate a curator's desk edit uses, credited
     * to the rider who sent it. Dismissing one applies nothing, the way a
     * dismissed photo correction attaches nothing.
     *
     * Values are re-checked against the route as it stands now, not as it
     * stood when the rider sent them: a field a curator has since changed to
     * the proposed value simply records no second history row.
     */
    private function applyMetadata(RecommendedRoute $route, RouteSuggestion $s, RouteSuggestionStatus $status, User $curator): void
    {
        $changes = $s->getChanges();
        if (RouteSuggestionStatus::Done !== $status || null === $changes || [] === $changes) {
            return;
        }
        $this->editMetadata(
            (int) $route->getId(),
            RouteMetadata::applyable($changes),
            $curator,
            author: $s->getUserId(),
            suggestionId: (int) $s->getId(),
        );
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

    /** Outcome message rides the decision transaction; skipped for imported routes. */
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
