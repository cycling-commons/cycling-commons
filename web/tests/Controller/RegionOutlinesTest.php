<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The world map on /regions reads one shape per country as GeoJSON, the
 * union of that country's stored outlines (owner 2026-09-08).
 */
final class RegionOutlinesTest extends WebTestCase
{
    public function testOneClosedShapePerCountry(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug('outline-test-'.bin2hex(random_bytes(3)))->setName('Outline test')->setCountryCode('XX')
            ->setGeom('{"type":"Polygon","coordinates":[[[5.0,50.0],[5.5,50.0],[5.5,50.5],[5.0,50.0]]]}');
        $em->persist($region);
        $em->flush();
        $em->getConnection()->executeStatement("UPDATE region SET outline = '[[5.0,50.0,5.5,50.0,5.5,50.5,5.0,50.5]]' WHERE id = :id", ['id' => $region->getId()]);

        $client->request('GET', '/regions/outlines.json');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'), 'on the public cache list');
        self::assertNotEmpty((string) $client->getResponse()->headers->get('ETag'));
        /** @var array{type: string, features: list<array{properties: array{slug: string, cc: string}, geometry: array{type: string, coordinates: list<list<list<float>>>}}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('FeatureCollection', $data['type']);
        $mine = array_values(array_filter($data['features'], static fn (array $f): bool => 'XX' === $f['properties']['cc']));
        self::assertCount(1, $mine);
        self::assertContains($mine[0]['geometry']['type'], ['Polygon', 'MultiPolygon']);
        $ring = 'Polygon' === $mine[0]['geometry']['type'] ? $mine[0]['geometry']['coordinates'][0] : $mine[0]['geometry']['coordinates'][0][0];
        self::assertSame($ring[0], $ring[\count($ring) - 1], 'closed');
        self::assertGreaterThanOrEqual(4, \count($ring));
    }

    /**
     * A country onboarded state by state draws those states, not itself.
     *
     * It also carries its level-2 outline, and unioning that in painted the
     * whole United States for California and Colorado: a rider in Ohio saw
     * their state filled in on three pages and found nothing there (owner
     * 2026-09-14). A country onboarded as one whole region still draws whole.
     */
    public function testACountryOnboardedByStateDrawsOnlyThoseStates(): void
    {
        $client = static::createClient();
        // Whole-country outline 0..10, one state 5..6 inside it.
        $this->region('XY', 2, [0.0, 40.0, 10.0, 40.0, 10.0, 50.0, 0.0, 50.0]);
        $this->region('XY', 4, [5.0, 44.0, 6.0, 44.0, 6.0, 45.0, 5.0, 45.0]);
        // A country onboarded whole: only its level-2 row.
        $this->region('XZ', 2, [20.0, 40.0, 21.0, 40.0, 21.0, 41.0, 20.0, 41.0]);

        $client->request('GET', '/regions/outlines.json');
        self::assertResponseIsSuccessful();
        /** @var array{features: list<array{properties: array{cc: string}, geometry: array{type: string, coordinates: array<mixed>}}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $xy = self::lonRange($data['features'], 'XY');
        self::assertGreaterThanOrEqual(5.0, $xy[0], 'the state, not the country: nothing west of it');
        self::assertLessThanOrEqual(6.0, $xy[1], 'and nothing east of it');

        $xz = self::lonRange($data['features'], 'XZ');
        self::assertSame([20.0, 21.0], $xz, 'a whole-country region still draws the country');
    }

    /** @param list<float> $flatRing */
    private function region(string $cc, int $level, array $flatRing): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pairs = [];
        for ($i = 0; $i < \count($flatRing); $i += 2) {
            $pairs[] = [$flatRing[$i], $flatRing[$i + 1]];
        }
        $pairs[] = $pairs[0];
        $region = (new Region())->setSlug('outline-lvl-'.bin2hex(random_bytes(4)))->setName('Outline '.$cc.' '.$level)
            ->setCountryCode($cc)->setAdminLevel($level)
            ->setGeom((string) json_encode(['type' => 'Polygon', 'coordinates' => [$pairs]]));
        $em->persist($region);
        $em->flush();
        $em->getConnection()->executeStatement('UPDATE region SET outline = :o WHERE id = :id', [
            'o' => json_encode([$flatRing]),
            'id' => $region->getId(),
        ]);
    }

    /**
     * @param list<array{properties: array{cc: string}, geometry: array{type: string, coordinates: array<mixed>}}> $features
     *
     * @return array{0: float, 1: float}
     */
    private static function lonRange(array $features, string $cc): array
    {
        $mine = array_values(array_filter($features, static fn (array $f): bool => $cc === $f['properties']['cc']));
        self::assertCount(1, $mine, $cc.' has one shape');
        $g = $mine[0]['geometry'];
        $polys = 'MultiPolygon' === $g['type'] ? $g['coordinates'] : [$g['coordinates']];
        $lons = [];
        foreach ($polys as $poly) {
            foreach ($poly[0] as $pt) {
                $lons[] = (float) $pt[0];
            }
        }

        return [min($lons), max($lons)];
    }
}
