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
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Service\AdminActionLogger;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

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
        private readonly ClockInterface $clock,
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
        // A correction credits its author, and nobody once that account is
        // deleted; never the curator who applied it.
        $creditTo = null !== $suggestionId ? $author : ($author ?? $curator->getId());

        return $this->em->wrapInTransaction(function () use ($routeId, $changes, $curator, $creditTo, $suggestionId): RecommendedRoute {
            $route = $this->load($routeId);
            $this->assertInScope($curator, $route->getRegionId());
            $attributes = $route->getAttributes();

            foreach ($changes as $field => $raw) {
                if (RouteMetadata::NAME_FIELD === $field) {
                    $this->renameRoute($route, $raw, $creditTo, $suggestionId);
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
                $this->em->persist(new RouteChangeHistory((int) $route->getId(), $field, $current, $new, $creditTo, $suggestionId));
            }
            $route->setAttributes($attributes);

            return $route;
        });
    }

    /**
     * A curator's save of the desk form (route-domain.md §5). Only the fields
     * the curator changed from what the form showed (`$shown`, the form's
     * values when it was rendered) go to {@see editMetadata()}, so a save
     * never writes back a value the curator left alone over a proposer's
     * revision made while the form was open (route-domain.md §4.6). A field
     * that changed on the route after the form was shown and that the curator
     * also changed is not written: the route keeps the newer value and the
     * field is returned, for the desk to name. A field missing from `$shown`
     * has the route's value now as its baseline. The row is locked and read
     * again for the comparison, so a proposer's revision either landed before
     * it or waits for this save to commit.
     *
     * @param array<string, mixed> $posted the form's fields, keyed as it posts them
     * @param array<string, mixed> $shown  the values the form showed, same keys
     *
     * @return list<string> the refused fields, keys from {@see RouteMetadata::EDITABLE_FIELDS}
     *
     * @throws OutOfScopeException       the route is outside the curator's areas
     * @throws \InvalidArgumentException as {@see editMetadata()}
     */
    public function editFromDesk(int $routeId, array $posted, array $shown, User $curator): array
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $posted, $shown, $curator): array {
            $route = $this->em->find(RecommendedRoute::class, $routeId, LockMode::PESSIMISTIC_WRITE);
            if (null === $route) {
                throw new \InvalidArgumentException(sprintf('Route %d not found.', $routeId));
            }
            $this->em->refresh($route);
            $attributes = $route->getAttributes();

            $changes = [];
            $refused = [];
            foreach ($posted as $field => $raw) {
                $field = (string) $field;
                if (!\in_array($field, RouteMetadata::EDITABLE_FIELDS, true)) {
                    $changes[$field] = $raw; // editMetadata() refuses it
                    continue;
                }
                if (RouteMetadata::NAME_FIELD === $field) {
                    $current = $route->getName();
                    $mine = \is_string($raw) ? trim($raw) : '';
                    $was = \is_string($shown[$field] ?? null) ? trim($shown[$field]) : $current;
                } else {
                    $current = RouteMetadata::canonical($field, $attributes[$field] ?? null);
                    $mine = RouteMetadata::canonical($field, $raw);
                    $was = \array_key_exists($field, $shown) ? RouteMetadata::canonical($field, $shown[$field]) : $current;
                }
                if (RouteMetadata::isSame($mine, $was) || RouteMetadata::isSame($mine, $current)) {
                    continue;
                }
                if (!RouteMetadata::isSame($was, $current)) {
                    $refused[] = $field;
                    continue;
                }
                $changes[$field] = $raw;
            }
            $this->editMetadata($routeId, $changes, $curator);

            return $refused;
        });
    }

    /** History files a rename under `name`, the column it changes. */
    private function renameRoute(RecommendedRoute $route, mixed $raw, ?int $creditTo, ?int $suggestionId): void
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
        if (!\in_array($status, [RouteSuggestionStatus::Done, RouteSuggestionStatus::Dismissed], true)) {
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
            // A curator's own correction applied at once owes nobody a message,
            // and neither does one whose rider deleted their account.
            $riderId = $s->getUserId();
            if (null === $riderId || $riderId === $curator->getId()) {
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
     * Move a route correction in any status to Trash: kept with its photos
     * and its thread for TrashBin::TRASH_DAYS days, hidden everywhere but the
     * Trash list, then purged (TrashBin). No message to the rider.
     *
     * @see docs/specs/moderation-and-contribution.md §6
     */
    public function trashSuggestion(int $id, User $curator): void
    {
        $this->em->wrapInTransaction(function () use ($id, $curator): void {
            $s = $this->em->find(RouteSuggestion::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (null === $s || $s->isTrashed()) {
                throw new \InvalidArgumentException(sprintf('Suggestion %d not found.', $id));
            }
            $route = $this->em->find(RecommendedRoute::class, $s->getRouteId());
            $this->assertInScope($curator, $route?->getRegionId());

            $this->adminLog->log($curator, TrashActions::TrashCorrection, null, sprintf('suggestion %d on route %d', $id, $s->getRouteId()));
            $now = $this->clock->now();
            $s->moveToTrash((int) $curator->getId(), $now);
            $this->messages->trashThread('correction', $id, $now);
        });
    }

    /**
     * Take a route correction out of Trash, back to the status it had, its
     * thread with it. No message to the rider.
     *
     * @throws \InvalidArgumentException when it is not in the bin
     *
     * @see docs/specs/moderation-and-contribution.md §6
     */
    public function restoreSuggestion(int $id, User $curator): RouteSuggestion
    {
        return $this->em->wrapInTransaction(function () use ($id, $curator): RouteSuggestion {
            $s = $this->em->find(RouteSuggestion::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (null === $s || !$s->isTrashed()) {
                throw new \InvalidArgumentException(sprintf('Suggestion %d is not in Trash.', $id));
            }
            $route = $this->em->find(RecommendedRoute::class, $s->getRouteId());
            $this->assertInScope($curator, $route?->getRegionId());

            $s->restoreFromTrash();
            $this->messages->restoreThread('correction', $id);
            $this->adminLog->log($curator, TrashActions::RestoreCorrection, null, sprintf('suggestion %d on route %d', $id, $s->getRouteId()));

            return $s;
        });
    }

    /**
     * Move a proposal to Trash, only while submitted or rejected: kept with
     * its photos and its thread for TrashBin::TRASH_DAYS days, then purged.
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
            $now = $this->clock->now();
            $route->moveToTrash((int) $curator->getId(), $now);
            $this->messages->trashThread('route', $routeId, $now);
        });
    }

    /**
     * Take a proposal out of Trash, back to submitted or rejected as it was,
     * its thread with it. No message to the proposer.
     *
     * @throws \InvalidArgumentException when it is not in the bin
     *
     * @see docs/specs/moderation-and-contribution.md §6
     */
    public function restoreProposal(int $routeId, User $curator): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $curator): RecommendedRoute {
            $route = $this->em->find(RecommendedRoute::class, $routeId, LockMode::PESSIMISTIC_WRITE);
            if (null === $route || !$route->isTrashed()) {
                throw new \InvalidArgumentException(sprintf('Route %d is not in Trash.', $routeId));
            }
            $this->assertInScope($curator, $route->getRegionId());

            $route->restoreFromTrash();
            $this->messages->restoreThread('route', $routeId);
            $this->adminLog->log($curator, TrashActions::RestoreRouteProposal, null, sprintf('route %d state=%s', $routeId, $route->getState()->value));

            return $route;
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
        $proposer = $route->getProposedBy();
        if (null === $proposer) {
            return;
        }
        $this->messages->sendSystem(
            $route->getProposedBy(), $kind,
            'route', (int) $route->getId(), $route->getName(),
            'messages.body.'.$kind->value, ['%name%' => $route->getName()],
            $note,
        );
    }

    /** A route in Trash is found only by restoreProposal(). */
    private function load(int $routeId): RecommendedRoute
    {
        $route = $this->em->find(RecommendedRoute::class, $routeId);
        if (null === $route || $route->isTrashed()) {
            throw new \InvalidArgumentException(sprintf('Route %d not found.', $routeId));
        }

        return $route;
    }
}
