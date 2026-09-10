<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Service;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Service\BaseLocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * map-and-search.md §4.5: BaseLocationService owns every base-location
 * write so the derived region/country set can never drift from the stored
 * (coarse) point. Region fixture follows BaseAreaResolverTest's box idiom
 * (open mid-Atlantic geometry, never real Belgian coordinates — seeded region
 * rows carry a real, non-null area_km2 that would beat a NULL-area fixture on
 * the ORDER BY area_km2 tie-break if the two ever overlapped), sized to
 * contain the coarsened probe point used below.
 */
final class BaseLocationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Region $region;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->region = new Region();
        $this->region->setSlug('base-location-test-'.bin2hex(random_bytes(4)))
            ->setName('Base location test box')
            ->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-46.00,0.30],[-45.00,0.30],[-45.00,0.60],[-46.00,0.60],[-46.00,0.30]]]]}');
        $this->em->persist($this->region);
        $this->em->flush();
    }

    private function makeUser(): User
    {
        $u = (new User())->setEmail('base-loc-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testApplyCoarsensDerivesAndSetsRadius(): void
    {
        $u = $this->makeUser();
        $svc = static::getContainer()->get(BaseLocationService::class);
        $svc->apply($u, 0.451234, -45.851234, 'Namur', 55);

        self::assertSame(0.45, $u->getBaseLat());
        self::assertSame(55, $u->getBaseRadiusKm());
        self::assertSame([$this->region->getId()], $u->getBaseRegionIds());
        self::assertSame(['BE'], $u->getBaseCountryCodes());
    }

    public function testApplyDoesNotFlush(): void
    {
        // apply() mutates the entity only — the caller owns the flush/transaction
        // (map-and-search.md §4.5).
        $u = $this->makeUser();
        $svc = static::getContainer()->get(BaseLocationService::class);
        $svc->apply($u, 0.451234, -45.851234, 'Namur', 55);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($u->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->hasBaseLocation());
    }

    public function testClearWipesBaseLocation(): void
    {
        $u = $this->makeUser();
        $svc = static::getContainer()->get(BaseLocationService::class);
        $svc->apply($u, 0.451234, -45.851234, 'Namur', 55);
        $this->em->flush();
        self::assertTrue($u->hasBaseLocation());

        $svc->clear($u);
        $this->em->flush();

        self::assertFalse($u->hasBaseLocation());
        self::assertSame([], $u->getBaseRegionIds());
        self::assertSame([], $u->getBaseCountryCodes());
    }

    public function testRederiveAllRestampsUsersInsideNewPolygons(): void
    {
        $u = $this->makeUser();
        $u->setBaseLocation(0.45, -45.85, null);
        $u->setBaseRegionIds([]); // stale
        $this->em->flush();

        $svc = static::getContainer()->get(BaseLocationService::class);
        $n = $svc->rederiveAll();

        self::assertSame(1, $n);
        $this->em->refresh($u);
        self::assertSame([$this->region->getId()], $u->getBaseRegionIds());
        self::assertSame(['BE'], $u->getBaseCountryCodes());
    }

    public function testRederiveAllSkipsUsersWithoutABasePoint(): void
    {
        $this->makeUser(); // no base point set
        $svc = static::getContainer()->get(BaseLocationService::class);

        self::assertSame(0, $svc->rederiveAll());
    }
}
