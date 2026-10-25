<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /regions/uncovered.json: the held areas the map does not cover yet, inside
 * the countries it partly covers (docs/specs/map-and-search.md §4.5b). The
 * map names one ("no data for Utah yet") from this file alone, so an area
 * that is covered, or one from a country the file is not about, must never
 * be in it.
 */
final class UncoveredAreasTest extends WebTestCase
{
    public function testOnlyTheUncoveredAreasOfAPartlyCoveredCountry(): void
    {
        $client = static::createClient();
        $tag = strtoupper(bin2hex(random_bytes(2)));

        // XQ: onboarded state by state. A is live, C is still pending, B has no region.
        $this->region('XQ', 2, null, false);
        $this->region('XQ', 4, 'XQ-A'.$tag, false);
        $this->region('XQ', 4, 'XQ-C'.$tag, true);
        $this->division('XQ', 'XQ-A'.$tag, 'Live state '.$tag);
        $this->division('XQ', 'XQ-B'.$tag, 'Open state '.$tag);
        $this->division('XQ', 'XQ-C'.$tag, 'Pending state '.$tag);
        // XW: onboarded as one whole-country region: nothing of it is uncovered.
        $this->region('XW', 2, null, false);
        $this->division('XW', 'XW-A'.$tag, 'Whole-country part '.$tag);
        // XV: not onboarded at all: the country notice covers it, not this file.
        $this->division('XV', 'XV-A'.$tag, 'Elsewhere '.$tag);

        $client->request('GET', '/regions/uncovered.json');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'), 'on the public cache list');
        /** @var array{type: string, features: list<array{properties: array{cc: string, iso: string, name: string}, geometry: array{type: string}}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('FeatureCollection', $data['type']);

        $names = array_map(static fn (array $f): string => $f['properties']['name'], $data['features']);
        self::assertContains('Open state '.$tag, $names, 'an area with no region is listed');
        self::assertContains('Pending state '.$tag, $names, 'a pending region is not on the map yet, so its area is listed');
        self::assertNotContains('Live state '.$tag, $names, 'a live region is never uncovered');
        self::assertNotContains('Whole-country part '.$tag, $names, 'a country covered as one region has nothing uncovered');
        self::assertNotContains('Elsewhere '.$tag, $names, 'a country not on the map is not in this file');

        foreach ($data['features'] as $f) {
            if ('Open state '.$tag === $f['properties']['name']) {
                self::assertSame('XQ', $f['properties']['cc']);
                self::assertSame('MultiPolygon', $f['geometry']['type']);
            }
        }
    }

    private function region(string $cc, int $level, ?string $iso, bool $pending): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug('uncovered-'.bin2hex(random_bytes(4)))->setName('Uncovered test '.$cc)
            ->setCountryCode($cc)->setAdminLevel($level)->setIsoCode($iso)
            ->setGeom('{"type":"Polygon","coordinates":[[[0.0,0.0],[1.0,0.0],[1.0,1.0],[0.0,0.0]]]}');
        $em->persist($region);
        $em->flush();
        if ($pending) {
            $em->getConnection()->executeStatement('UPDATE region SET pending = true WHERE id = :id', ['id' => $region->getId()]);
        }
    }

    private function division(string $cc, string $iso, string $name): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO world_division (country_code, iso_code, name, subtype, geom, area_km2, source, created_at, updated_at)
             VALUES (:cc, :iso, :name, 'region', ST_GeomFromText('MULTIPOLYGON(((10 10, 12 10, 12 12, 10 12, 10 10)))', 4326), 49000, 'test', NOW(), NOW())",
            ['cc' => $cc, 'iso' => $iso, 'name' => $name],
        );
    }
}
