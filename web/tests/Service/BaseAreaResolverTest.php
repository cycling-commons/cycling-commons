<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Service;

use App\Catalog\Entity\Region;
use App\Service\BaseAreaResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * region-scoping-design.md §3 "User base location": deriving the My-area
 * region set from a coarse base point + radius. Fixture idiom follows
 * RegionResolverTest (box MultiPolygon GeoJSON, randomised slugs, explicit
 * teardown) — including placing every fixture in the open mid-Atlantic near
 * the equator, never on real Belgian coordinates. Seeded region rows (e.g.
 * Wallonia) carry a real, non-null area_km2, which would beat a NULL-area
 * fixture on the ORDER BY area_km2 tie-break if the two ever overlapped, so
 * this suite cannot reuse real-world geography the way the earlier version
 * of this file mistakenly did.
 *
 * Shared fixture geometry (lng/lat corners, all boxes non-overlapping). Near
 * the equator both axes convert at ~111.32 km/degree, so no cos(lat) factor
 * is needed when reasoning about distances below:
 *  - A (BE): [-45.40,0.30 -> -45.00,0.60] contains the probe point
 *    (-45.15,0.45).
 *  - B (BE): [-45.00,0.30 -> -44.60,0.60] adjacent east of A, ~16.7 km from
 *    the probe: within every 40 km-radius test.
 *  - C (NL): [-45.40,0.90 -> -45.00,1.20] north of A with a real gap (A's
 *    north edge sits at lat 0.60, C's south edge at 0.90 -> ~50 km from the
 *    probe, so it is excluded at 40 km from the probe but included near the
 *    A/C border).
 *  - FAR (DE): [-41.00,2.00 -> -40.60,2.30], several hundred km away:
 *    excluded from every radius used here.
 */
final class BaseAreaResolverTest extends KernelTestCase
{
    private function makeRegion(
        EntityManagerInterface $em,
        string $cc,
        float $w,
        float $s,
        float $e,
        float $n,
        ?float $area = null,
    ): Region {
        $region = new Region();
        $region->setSlug('base-area-test-'.bin2hex(random_bytes(4)))
            ->setName(\sprintf('Base area test box (%s)', '' === $cc ? 'no-cc' : $cc))
            ->setCountryCode($cc)
            ->setAreaKm2($area)
            ->setGeom(\sprintf(
                '{"type":"MultiPolygon","coordinates":[[[[%1$F,%2$F],[%3$F,%2$F],[%3$F,%4$F],[%1$F,%4$F],[%1$F,%2$F]]]]}',
                $w,
                $s,
                $e,
                $n,
            ));
        $em->persist($region);

        return $region;
    }

    public function testContainingRegionAlwaysFirstAndNeighboursByDistance(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(BaseAreaResolver::class);

        $a = $this->makeRegion($em, 'BE', -45.40, 0.30, -45.00, 0.60);
        $b = $this->makeRegion($em, 'BE', -45.00, 0.30, -44.60, 0.60);
        $far = $this->makeRegion($em, 'DE', -41.00, 2.00, -40.60, 2.30);
        $em->flush();

        $r = $resolver->resolve(0.45, -45.15, 40);

        self::assertSame($a->getId(), $r['regionIds'][0]);
        self::assertContains($b->getId(), $r['regionIds']);
        self::assertNotContains($far->getId(), $r['regionIds']);
        self::assertSame(['BE'], $r['countryCodes']);

        $em->remove($a);
        $em->remove($b);
        $em->remove($far);
        $em->flush();
    }

    public function testCrossBorderCircleSpansCountries(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(BaseAreaResolver::class);

        $a = $this->makeRegion($em, 'BE', -45.40, 0.30, -45.00, 0.60);
        $b = $this->makeRegion($em, 'BE', -45.00, 0.30, -44.60, 0.60);
        $c = $this->makeRegion($em, 'NL', -45.40, 0.90, -45.00, 1.20);
        $em->flush();

        // Just inside A, close to the A/C border (A's north edge is lat 0.60).
        $r = $resolver->resolve(0.58, -45.15, 40);

        self::assertSame($a->getId(), $r['regionIds'][0]);
        self::assertContains($c->getId(), $r['regionIds']);
        self::assertSame(['BE', 'NL'], $r['countryCodes']);

        $em->remove($a);
        $em->remove($b);
        $em->remove($c);
        $em->flush();
    }

    public function testCapAtEightRegions(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(BaseAreaResolver::class);

        // 3x4 grid of non-overlapping 0.05-degree slivers, all well within
        // 150 km of the grid centre, so the cap (not the radius) is what
        // truncates the result to MAX_REGIONS.
        $regions = [];
        for ($latIdx = 0; $latIdx < 3; ++$latIdx) {
            $s = 0.30 + 0.06 * $latIdx;
            $n = $s + 0.05;
            for ($lngIdx = 0; $lngIdx < 4; ++$lngIdx) {
                $w = -45.40 + 0.10 * $lngIdx;
                $e = $w + 0.05;
                $regions[] = $this->makeRegion($em, 'BE', $w, $s, $e, $n);
            }
        }
        $em->flush();

        self::assertCount(12, $regions);

        $r = $resolver->resolve(0.385, -45.225, 150);

        self::assertCount(BaseAreaResolver::MAX_REGIONS, $r['regionIds']);

        foreach ($regions as $region) {
            $em->remove($region);
        }
        $em->flush();
    }

    public function testNoContainingRegionStillReturnsNearby(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(BaseAreaResolver::class);

        $a = $this->makeRegion($em, 'BE', -45.40, 0.30, -45.00, 0.60);
        $em->flush();

        // A sea-gap point just west of A's west edge (-45.40), ~5.6 km away:
        // outside every region, but within the 40 km radius of A.
        $r = $resolver->resolve(0.45, -45.45, 40);

        self::assertContains($a->getId(), $r['regionIds']);

        $em->remove($a);
        $em->flush();
    }

    public function testEmptyCountryCodeIsSkippedButRegionIsIncluded(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(BaseAreaResolver::class);

        $a = $this->makeRegion($em, 'BE', -45.40, 0.30, -45.00, 0.60);
        // Same footprint as fixture B above, but with no country assigned.
        $d = $this->makeRegion($em, '', -45.00, 0.30, -44.60, 0.60);
        $em->flush();

        $r = $resolver->resolve(0.45, -45.15, 40);

        self::assertContains($d->getId(), $r['regionIds']);
        self::assertSame(['BE'], $r['countryCodes']);

        $em->remove($a);
        $em->remove($d);
        $em->flush();
    }

    public function testNoRegionsWithinRadiusReturnsEmptySet(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $resolver = static::getContainer()->get(BaseAreaResolver::class);

        $a = $this->makeRegion($em, 'BE', -45.40, 0.30, -45.00, 0.60);
        $em->flush();

        // Thousands of km from A: nothing within a 40 km radius.
        $r = $resolver->resolve(20.00, -45.15, 40);

        self::assertSame(['regionIds' => [], 'countryCodes' => []], $r);

        $em->remove($a);
        $em->flush();
    }
}
