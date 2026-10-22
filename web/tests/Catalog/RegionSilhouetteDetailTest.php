<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use App\Catalog\RegionDerivations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `region.silhouette` keeps the detail its drawing can show (map-and-search.md
 * §4.6): a small country keeps a coastline's teeth and its islands, a large
 * one drops what its smaller drawing cannot show, and a region is simplified
 * at its country's scale, the frame it is drawn in.
 */
final class RegionSilhouetteDetailTest extends KernelTestCase
{
    /** Teeth 0.004 degrees deep, every 0.01 degrees along the south edge. */
    private const float TOOTH = 0.004;

    public function testASmallCountryKeepsItsCoastAndItsIslands(): void
    {
        self::bootKernel();
        $country = $this->region('XQ', 2, [self::toothed(5.0, 6.0, 50.0, 51.0), self::square(5.2, 51.1, 0.03)]);

        $rings = $this->silhouette($country);

        self::assertCount(2, $rings, 'the island, 0.08 % of the land, stays: it is three drawing units wide');
        self::assertGreaterThan(100, \count($rings[0]) / 2, 'every tooth of the coast stays');
    }

    public function testALargeCountryAndItsRegionsDropWhatTheDrawingCannotShow(): void
    {
        self::bootKernel();
        $country = $this->region('XR', 2, [self::toothed(0.0, 10.0, 40.0, 50.0)]);
        // One degree wide: drawn alone it would keep its teeth, drawn in its country it cannot show them.
        $region = $this->region('XR', 4, [self::toothed(2.0, 3.0, 40.0, 41.0)]);

        self::assertLessThan(20, \count($this->silhouette($country)[0]) / 2);
        self::assertLessThan(20, \count($this->silhouette($region)[0]) / 2);
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $polygons outer rings, closed
     */
    private function region(string $cc, int $level, array $polygons): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug('silhouette-'.bin2hex(random_bytes(4)))->setName('Silhouette '.$cc.' '.$level)
            ->setCountryCode($cc)->setAdminLevel($level)
            ->setGeom((string) json_encode(['type' => 'MultiPolygon', 'coordinates' => array_map(static fn (array $ring): array => [$ring], $polygons)]));
        $em->persist($region);
        $em->flush();

        return (int) $region->getId();
    }

    /** @return list<list<float>> */
    private function silhouette(int $id): array
    {
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement(RegionDerivations::silhouetteSql());
        /** @var list<list<float>> $rings */
        $rings = json_decode((string) $db->fetchOne('SELECT silhouette FROM region WHERE id = :id', ['id' => $id]), true, 512, \JSON_THROW_ON_ERROR);

        return $rings;
    }

    /**
     * A square whose south edge is a row of teeth.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function toothed(float $west, float $east, float $south, float $north): array
    {
        $ring = [];
        for ($i = 0, $n = (int) round(($east - $west) / 0.01); $i <= $n; ++$i) {
            $ring[] = [round($west + $i * 0.01, 4), $south + ($i % 2) * self::TOOTH];
        }
        $ring[] = [$east, $north];
        $ring[] = [$west, $north];
        $ring[] = $ring[0];

        return $ring;
    }

    /** @return list<array{0: float, 1: float}> */
    private static function square(float $west, float $south, float $side): array
    {
        return [[$west, $south], [$west + $side, $south], [$west + $side, $south + $side], [$west, $south + $side], [$west, $south]];
    }
}
