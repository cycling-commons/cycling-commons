<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * coverage-provider.md §4: the scope dim mask needs a
 * single unioned boundary for a whole country (or an explicit region set), so
 * the map can grey everything outside a country scope, not just a named region.
 */
final class ScopeBoundaryTest extends WebTestCase
{
    private function seedTwo(EntityManagerInterface $em): void
    {
        // two adjacent NL squares; their union is one polygon
        $em->persist((new Region())->setSlug('sb-a')->setName('A')->setCountryCode('NL')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4,52],[5,52],[5,53],[4,53],[4,52]]]]}'));
        $em->persist((new Region())->setSlug('sb-b')->setName('B')->setCountryCode('NL')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[5,52],[6,52],[6,53],[5,53],[5,52]]]]}'));
        $em->flush();
    }

    public function testCountryUnionReturnsOneFeature(): void
    {
        $client = static::createClient();
        $this->seedTwo(static::getContainer()->get(EntityManagerInterface::class));
        $client->request('GET', '/map/scope/boundary?cc=NL');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Feature', $body['type']);
        self::assertContains($body['geometry']['type'], ['Polygon', 'MultiPolygon']);
        self::assertNotEmpty($client->getResponse()->getEtag());
    }

    public function testEmptyScopeIsNoContent(): void
    {
        static::createClient()->request('GET', '/map/scope/boundary');
        self::assertSame(204, static::getClient()->getResponse()->getStatusCode());
    }

    public function testArrayValuedRidsDegradesToEmptyScopeInsteadOf400(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map/scope/boundary?rids[]=1&rids[]=2');
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    /**
     * A rids-only call (no cc) must union exactly the requested regions —
     * never every region still carrying the schema-default '' country_code,
     * which is what a static `... OR country_code = :cc` bound to '' would
     * pull in. Seeds a third, geographically distant region with the
     * default '' country_code and asserts its coordinates are absent from
     * the union returned for the two NL regions alone.
     */
    public function testRidsOnlyExcludesDefaultCountryCodeRegion(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seedTwo($em);
        // Far from the NL squares (lng 4-6); country_code left at its '' default.
        $em->persist((new Region())->setSlug('sb-z')->setName('Z')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[100,10],[101,10],[101,11],[100,11],[100,10]]]]}'));
        $em->flush();

        $repo = $em->getRepository(Region::class);
        $a = $repo->findOneBy(['slug' => 'sb-a']);
        $b = $repo->findOneBy(['slug' => 'sb-b']);
        self::assertNotNull($a);
        self::assertNotNull($b);

        $client->request('GET', \sprintf('/map/scope/boundary?rids=%d,%d', $a->getId(), $b->getId()));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Feature', $body['type']);

        $coords = [];
        $this->flattenCoordinates($body['geometry']['coordinates'], $coords);
        self::assertNotEmpty($coords);
        foreach ($coords as [$lng, $lat]) {
            // sb-z sits at lng 100-101; the NL squares span lng 4-6, so a
            // wide, still-deterministic margin at 50 catches any leak.
            self::assertLessThan(50, $lng, 'union must not include the far-away default-cc region');
        }
    }

    /**
     * @param array<int, mixed>               $node
     * @param list<array{0: float, 1: float}> $out
     */
    private function flattenCoordinates(array $node, array &$out): void
    {
        if (2 === \count($node) && \is_numeric($node[0] ?? null) && \is_numeric($node[1] ?? null)) {
            $out[] = [(float) $node[0], (float) $node[1]];

            return;
        }
        foreach ($node as $child) {
            if (\is_array($child)) {
                $this->flattenCoordinates($child, $out);
            }
        }
    }
}
