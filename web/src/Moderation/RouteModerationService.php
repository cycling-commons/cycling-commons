<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ONLY write-path for route moderation (spec §6, D1 — purpose-built, no
 * reuse of ModerationService). Every transition is transactional and appends
 * route_change_history (D9). The region cap (D8) is enforced on approve.
 *
 * @api Called by RouteModerateController.
 */
final class RouteModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $regionActiveCap,
    ) {
    }

    public function approve(int $routeId, User $curator): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $curator): RecommendedRoute {
            $route = $this->load($routeId);
            if (ItemState::Submitted !== $route->getState()) {
                throw new \LogicException('Only a submitted route can be approved.');
            }
            if ($this->activeCountForRegion($route->getRegionId()) >= $this->regionActiveCap) {
                throw new RegionFullException(sprintf('Region %s is at the active-route cap.', $route->getRegionId() ?? 'none'));
            }
            $this->transition($route, ItemState::Unverified, $curator);

            return $route;
        });
    }

    public function reject(int $routeId, User $curator, ?string $note): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $curator, $note): RecommendedRoute {
            $route = $this->load($routeId);
            if (ItemState::Submitted !== $route->getState()) {
                throw new \LogicException('Only a submitted route can be rejected.');
            }
            $this->transition($route, ItemState::Rejected, $curator, $note);

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
            if (!\in_array($route->getState(), ItemState::SERVED, true)) {
                throw new \LogicException('Only an active (unverified/verified) route can be retired.');
            }
            $this->transition($route, ItemState::Retired, $curator, $note);

            return $route;
        });
    }

    /**
     * @param array<string, mixed> $changes field => proposed value; `name` is a
     *                                       pseudo-field (Route::name), all others attributes
     */
    public function editMetadata(int $routeId, array $changes, User $curator): RecommendedRoute
    {
        return $this->em->wrapInTransaction(function () use ($routeId, $changes, $curator): RecommendedRoute {
            $route = $this->load($routeId);
            $attributes = $route->getAttributes();

            foreach ($changes as $field => $new) {
                $current = 'name' === $field ? $route->getName() : ($attributes[$field] ?? null);
                if ($current === $new || (null === $new && null === ($current ?? null))) {
                    continue; // no-op — never snapshot an unchanged field
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

    private function load(int $routeId): RecommendedRoute
    {
        $route = $this->em->find(RecommendedRoute::class, $routeId);
        if (null === $route) {
            throw new \InvalidArgumentException(sprintf('Route %d not found.', $routeId));
        }

        return $route;
    }
}
