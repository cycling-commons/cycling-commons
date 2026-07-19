<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteVote;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteRankingService;
use App\Catalog\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RouteRankingServiceTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $bikeTypes */
    private function route(EntityManagerInterface $em, ItemState $state, int $regionId, array $bikeTypes = []): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('R'.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)
            ->setSource(ItemSource::User)->setSourceRef('user:rank-'.uniqid())->setRegionId($regionId);
        if ([] !== $bikeTypes) {
            $r->setAttributes(['bikeTypes' => $bikeTypes]);
        }
        $em->persist($r);
        $em->flush();

        return $r;
    }

    private function vote(EntityManagerInterface $em, int $routeId, int $userId, Season $s, BikeType $b): void
    {
        $em->persist(new RouteVote($routeId, $userId, $s, $b));
        $em->flush();
    }

    public function testRanksByVoteCountThenRecency(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = static::getContainer()->get(RouteRankingService::class);

        $a = $this->route($em, ItemState::Verified, 1);   // 1 spring/gravel vote
        $b = $this->route($em, ItemState::Verified, 1);   // 2 spring/gravel votes → ranks first
        $this->vote($em, $a->getId(), 10, Season::Spring, BikeType::Gravel);
        $this->vote($em, $b->getId(), 11, Season::Spring, BikeType::Gravel);
        $this->vote($em, $b->getId(), 12, Season::Spring, BikeType::Gravel);

        self::assertSame([$b->getId(), $a->getId()], $svc->bestOf(Season::Spring, BikeType::Gravel, null));
    }

    public function testExcludesZeroVoteAndOtherFacets(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = static::getContainer()->get(RouteRankingService::class);

        $voted = $this->route($em, ItemState::Verified, 1);
        $this->route($em, ItemState::Verified, 1);                 // zero votes → excluded
        $this->vote($em, $voted->getId(), 10, Season::Spring, BikeType::Gravel);
        $this->vote($em, $voted->getId(), 11, Season::Summer, BikeType::Road);   // other facet, ignored

        self::assertSame([$voted->getId()], $svc->bestOf(Season::Spring, BikeType::Gravel, null));
    }

    public function testAllBikesAggregatesAcrossBikeTypes(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = static::getContainer()->get(RouteRankingService::class);

        $r = $this->route($em, ItemState::Verified, 1);
        $this->vote($em, $r->getId(), 10, Season::Spring, BikeType::Gravel);
        $this->vote($em, $r->getId(), 11, Season::Spring, BikeType::Road);

        self::assertSame([$r->getId()], $svc->bestOf(Season::Spring, null, null));   // both count
        self::assertSame([$r->getId()], $svc->bestOf(Season::Spring, BikeType::Gravel, null)); // one counts, still listed
    }

    public function testSpecialtyTypeRequiresDeclaredSuitability(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = static::getContainer()->get(RouteRankingService::class);

        $suitable = $this->route($em, ItemState::Verified, 1, ['Handbike', 'Gravel']);
        $notDeclared = $this->route($em, ItemState::Verified, 1, ['Gravel']);   // handbike-voted but not declared
        $this->vote($em, $suitable->getId(), 10, Season::Spring, BikeType::Handbike);
        $this->vote($em, $notDeclared->getId(), 11, Season::Spring, BikeType::Handbike);

        // Only the declared-suitable route appears in the Handbike list (P4-D4).
        self::assertSame([$suitable->getId()], $svc->bestOf(Season::Spring, BikeType::Handbike, null));
        // But a general type (Gravel) is NOT gated by suitability — vote alone suffices.
        $this->vote($em, $notDeclared->getId(), 12, Season::Spring, BikeType::Gravel);
        self::assertContains($notDeclared->getId(), $svc->bestOf(Season::Spring, BikeType::Gravel, null));
    }

    public function testEverywhereFacetIsHardCapped(): void
    {
        // Without a region filter the aggregate is unbounded (route-domain.md
        // §12 item 1); the Phase 1 guard caps it at MAX_RESULTS. Seed one more
        // than the cap, all qualifying (verified + one spring/gravel vote), and
        // assert the no-region facet returns exactly the cap.
        self::bootKernel();
        $em = $this->em();
        $svc = static::getContainer()->get(RouteRankingService::class);

        $routes = [];
        for ($i = 0; $i <= RouteRankingService::MAX_RESULTS; ++$i) {
            $r = (new RecommendedRoute())->setName('Cap'.$i)
                ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
                ->setDistanceM(20000)->setState(ItemState::Verified)
                ->setSource(ItemSource::User)->setSourceRef('user:cap-'.uniqid().'-'.$i);
            $em->persist($r);
            $routes[] = $r;
        }
        $em->flush();
        $uid = 1000;
        foreach ($routes as $r) {
            $em->persist(new RouteVote($r->getId(), $uid++, Season::Spring, BikeType::Gravel));
        }
        $em->flush();

        $ids = $svc->bestOf(Season::Spring, BikeType::Gravel, null);
        self::assertCount(RouteRankingService::MAX_RESULTS, $ids);
    }

    public function testRegionScoping(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = static::getContainer()->get(RouteRankingService::class);

        $r1 = $this->route($em, ItemState::Verified, 1);
        $r2 = $this->route($em, ItemState::Verified, 2);
        $this->vote($em, $r1->getId(), 10, Season::Spring, BikeType::Gravel);
        $this->vote($em, $r2->getId(), 11, Season::Spring, BikeType::Gravel);

        self::assertSame([$r1->getId()], $svc->bestOf(Season::Spring, BikeType::Gravel, 1));
    }
}
