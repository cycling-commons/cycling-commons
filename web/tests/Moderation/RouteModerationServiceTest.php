<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Moderation\RegionFullException;
use App\Moderation\RouteModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RouteModerationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RouteModerationService $svc;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->svc = static::getContainer()->get(RouteModerationService::class);
    }

    private function curator(): User
    {
        $u = (new User())->setEmail('rc-'.bin2hex(random_bytes(4)).'@test.test');
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function route(ItemState $state, ?int $regionId = 1): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('R '.bin2hex(random_bytes(3)))
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:'.bin2hex(random_bytes(8)))->setRegionId($regionId);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    public function testApproveMovesSubmittedToUnverifiedAndLogsHistory(): void
    {
        $route = $this->route(ItemState::Submitted);
        $curator = $this->curator();

        $this->svc->approve((int) $route->getId(), $curator);

        $this->em->clear();
        self::assertSame(ItemState::Unverified, $this->em->find(RecommendedRoute::class, $route->getId())->getState());
        $log = $this->em->getRepository(RouteChangeHistory::class)->findOneBy(['routeId' => $route->getId(), 'field' => 'state']);
        self::assertNotNull($log);
        self::assertSame('submitted', $log->getOldValue());
        self::assertSame('unverified', $log->getNewValue());
    }

    public function testApproveIntoAFullRegionIsBlocked(): void
    {
        // Fill region 7 to the cap with active routes, then a submitted one can't approve.
        $cap = (int) static::getContainer()->getParameter('route.region_active_cap');
        for ($i = 0; $i < $cap; ++$i) {
            $this->route(ItemState::Verified, regionId: 7);
        }
        $pending = $this->route(ItemState::Submitted, regionId: 7);
        $curator = $this->curator();

        $this->expectException(RegionFullException::class);
        $this->svc->approve((int) $pending->getId(), $curator);
    }

    public function testRetireFreesASlotAndRequiresANote(): void
    {
        $active = $this->route(ItemState::Verified, regionId: 3);
        $curator = $this->curator();

        $this->svc->retire((int) $active->getId(), $curator, 'Superseded by a better loop.');

        $this->em->clear();
        self::assertSame(ItemState::Retired, $this->em->find(RecommendedRoute::class, $active->getId())->getState());
        self::assertSame(0, $this->svc->activeCountForRegion(3));
    }

    public function testRetireWithoutANoteIsRejected(): void
    {
        $active = $this->route(ItemState::Verified, regionId: 3);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->retire((int) $active->getId(), $this->curator(), '   ');
    }

    public function testEditMetadataAppliesOnlyChangedFieldsWithHistory(): void
    {
        $route = $this->route(ItemState::Unverified);
        $route->setName('Old name')->setAttributes(['note' => 'Old note', 'season' => 'Spring']);
        $this->em->flush();
        $curator = $this->curator();

        $this->svc->editMetadata((int) $route->getId(), [
            'name' => 'Old name',                 // unchanged — must NOT snapshot
            'note' => 'A resurfaced descent now', // changed
            'season' => 'Spring',                 // unchanged
        ], $curator);

        $this->em->clear();
        $reloaded = $this->em->find(RecommendedRoute::class, $route->getId());
        self::assertSame('A resurfaced descent now', $reloaded->getAttributes()['note']);
        self::assertSame('Old name', $reloaded->getName());

        $rows = $this->em->getRepository(RouteChangeHistory::class)->findBy(['routeId' => $route->getId()]);
        $fields = array_map(static fn (RouteChangeHistory $h): string => $h->getField(), $rows);
        self::assertSame(['note'], $fields, 'only the changed field is snapshotted');
        self::assertSame('Old note', $rows[0]->getOldValue());
        self::assertSame('A resurfaced descent now', $rows[0]->getNewValue());
    }

    public function testResolveSuggestionMarksItDone(): void
    {
        $route = $this->route(ItemState::Unverified);
        $s = new \App\Catalog\Entity\RouteSuggestion((int) $route->getId(), 99, \App\Catalog\RouteSuggestionReason::Duplicate, 'Same as #12');
        $this->em->persist($s);
        $this->em->flush();
        $curator = $this->curator();

        $this->svc->resolveSuggestion((int) $s->getId(), \App\Catalog\RouteSuggestionStatus::Dismissed, $curator);

        $this->em->clear();
        $reloaded = $this->em->find(\App\Catalog\Entity\RouteSuggestion::class, $s->getId());
        self::assertSame(\App\Catalog\RouteSuggestionStatus::Dismissed, $reloaded->getStatus());
        self::assertSame($curator->getId(), $reloaded->getResolvedBy());
    }
}
