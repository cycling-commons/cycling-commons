<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * map-and-search.md §4.5: the injected region registry
 * (window.CC_REGIONS) must carry a localized `label` + `countryLabel` so the
 * client can search and render scope chips without re-fetching or re-translating.
 */
final class MapScopeRegistryTest extends WebTestCase
{
    public function testInjectedRegionsCarryLabels(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Region())->setSlug('bayern')->setName('Bavaria')->setCountryCode('DE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[10,48],[12,48],[12,50],[10,50],[10,48]]]]}'));
        $em->flush();

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // CC_REGIONS is injected as a JSON array literal; the bayern row must carry a label.
        self::assertMatchesRegularExpression(
            '/"slug":"bayern"[^}]*"label":"[^"]+"/',
            preg_replace('/\s+/', '', $html) ?? '',
            'CC_REGIONS bayern row is missing a label',
        );
        self::assertStringContainsString('"countryLabel":', $html);
    }
}
