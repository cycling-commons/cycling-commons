<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteMetadata;
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

    public function testRejectMovesSubmittedToRejectedAndLogsHistory(): void
    {
        $route = $this->route(ItemState::Submitted);
        $curator = $this->curator();

        $this->svc->reject((int) $route->getId(), $curator, 'Some rejection note');

        $this->em->clear();
        self::assertSame(ItemState::Rejected, $this->em->find(RecommendedRoute::class, $route->getId())->getState());

        $stateLog = $this->em->getRepository(RouteChangeHistory::class)->findOneBy(['routeId' => $route->getId(), 'field' => 'state']);
        self::assertNotNull($stateLog);
        self::assertSame('submitted', $stateLog->getOldValue());
        self::assertSame('rejected', $stateLog->getNewValue());

        $noteLog = $this->em->getRepository(RouteChangeHistory::class)->findOneBy(['routeId' => $route->getId(), 'field' => 'decision_note']);
        self::assertNotNull($noteLog);
        self::assertSame('Some rejection note', $noteLog->getNewValue());
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
        $route->setName('Old name')->setAttributes(['note' => 'Old note', 'season' => ['Spring']]);
        $this->em->flush();
        $curator = $this->curator();

        $this->svc->editMetadata((int) $route->getId(), [
            RouteMetadata::NAME_FIELD => 'Old name',  // unchanged — must NOT snapshot
            'note' => 'A resurfaced descent now',     // changed
            'season' => ['Spring'],                   // unchanged
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

    /** A curator's value is stored in the same shape the proposal form stores. */
    public function testEditMetadataStoresEveryFieldCanonically(): void
    {
        $route = $this->route(ItemState::Unverified);
        $curator = $this->curator();

        $this->svc->editMetadata((int) $route->getId(), [
            RouteMetadata::NAME_FIELD => 'Liege Bastogne Liege',
            'difficulty' => 'Very hard',
            'season' => ['Spring', 'Autumn'],
            'dominantSurface' => 'Asphalt',
            'note' => '  Steep through the Ardennes.  ',
            'bikeTypes' => ['Road', 'Gravel', 'Road'],
            'gradientLimited' => '≤9%',
            'bestDirection' => 'Clockwise',
        ], $curator);

        $this->em->clear();
        $reloaded = $this->em->find(RecommendedRoute::class, $route->getId());
        $attrs = $reloaded->getAttributes();
        self::assertSame('Liege Bastogne Liege', $reloaded->getName());
        // jsonb hands the object back in its own key order; the content is the value.
        self::assertEquals(['score' => 5, 'label' => 'Very hard'], $attrs['difficulty']);
        self::assertSame(['Spring', 'Autumn'], $attrs['season']);
        self::assertSame('Asphalt', $attrs['dominantSurface']);
        self::assertSame('Steep through the Ardennes.', $attrs['note']);
        self::assertSame(['Road', 'Gravel'], $attrs['bikeTypes'], 'deduplicated');
        self::assertSame('≤9%', $attrs['gradientLimited']);
        self::assertSame('Clockwise', $attrs['bestDirection']);

        $fields = array_map(
            static fn (RouteChangeHistory $h): string => $h->getField(),
            $this->em->getRepository(RouteChangeHistory::class)->findBy(['routeId' => $route->getId()]),
        );
        self::assertSame(
            ['name', 'difficulty', 'season', 'dominantSurface', 'note', 'bikeTypes', 'gradientLimited', 'bestDirection'],
            $fields,
            'every field change is recorded, the rename under `name`',
        );
    }

    /** Saving a field empty clears it: an absent key, never a blank. */
    public function testEditMetadataClearsAFieldSentEmpty(): void
    {
        $route = $this->route(ItemState::Unverified);
        $route->setAttributes(['gradientLimited' => '≤6%', 'note' => 'Old note']);
        $this->em->flush();
        $curator = $this->curator();

        $this->svc->editMetadata((int) $route->getId(), [
            'gradientLimited' => '',
            'note' => '   ',
        ], $curator);

        $this->em->clear();
        $attrs = $this->em->find(RecommendedRoute::class, $route->getId())->getAttributes();
        self::assertArrayNotHasKey('gradientLimited', $attrs);
        self::assertArrayNotHasKey('note', $attrs);

        $rows = $this->em->getRepository(RouteChangeHistory::class)->findBy(['routeId' => $route->getId()]);
        self::assertCount(2, $rows);
        self::assertNull($rows[0]->getNewValue(), 'a cleared field is recorded as unset');
    }

    /** Re-saving a route unchanged writes no history at all. */
    public function testEditMetadataSnapshotsNothingWhenTheFormComesBackUnchanged(): void
    {
        $route = $this->route(ItemState::Unverified);
        $route->setName('Liege Bastogne Liege')->setAttributes([
            // Key order as PostgreSQL hands the JSON back, not as the vocabulary builds it.
            'difficulty' => ['label' => 'Very hard', 'score' => 5],
            'bikeTypes' => ['Road'],
        ]);
        $this->em->flush();
        $curator = $this->curator();

        $this->svc->editMetadata((int) $route->getId(), [
            RouteMetadata::NAME_FIELD => 'Liege Bastogne Liege',
            'difficulty' => 'Very hard',
            'season' => [],
            'dominantSurface' => '',
            'note' => '',
            'bikeTypes' => ['Road'],
            'gradientLimited' => '',
            'bestDirection' => '',
        ], $curator);

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(RouteChangeHistory::class)->findBy(['routeId' => $route->getId()]));
    }

    public function testEditMetadataRefusesAFieldOutsideTheRegistry(): void
    {
        $route = $this->route(ItemState::Unverified);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->editMetadata((int) $route->getId(), ['surfaces' => 'anything'], $this->curator());
    }

    public function testEditMetadataRefusesToBlankARouteName(): void
    {
        $route = $this->route(ItemState::Unverified);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->editMetadata((int) $route->getId(), [RouteMetadata::NAME_FIELD => '  '], $this->curator());
    }

    /** A value outside the vocabulary is not a value: it never reaches `attributes`. */
    public function testEditMetadataDropsAValueOutsideTheVocabulary(): void
    {
        $route = $this->route(ItemState::Unverified);
        $curator = $this->curator();

        $this->svc->editMetadata((int) $route->getId(), [
            'gradientLimited' => 'BOGUS',
            'bikeTypes' => ['Road', 'Hovercraft'],
        ], $curator);

        $this->em->clear();
        $attrs = $this->em->find(RecommendedRoute::class, $route->getId())->getAttributes();
        self::assertArrayNotHasKey('gradientLimited', $attrs);
        self::assertSame(['Road'], $attrs['bikeTypes']);
    }

    public function testResolveSuggestionMarksItDone(): void
    {
        $route = $this->route(ItemState::Unverified);
        // Task 4: resolveSuggestion now messages the suggester — user_message.user_id
        // has a real FK to users(id), so the suggestion's userId must be a persisted user.
        $suggester = $this->curator();
        $s = new \App\Catalog\Entity\RouteSuggestion((int) $route->getId(), (int) $suggester->getId(), \App\Catalog\RouteSuggestionReason::Duplicate, 'Same as #12');
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
