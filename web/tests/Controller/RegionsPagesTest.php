<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegionsPagesTest extends WebTestCase
{
    private const GEOM = '{"type":"MultiPolygon","coordinates":[[[[4,50],[5,50],[5,51],[4,51],[4,50]]]]}';

    private function seedRegion(string $slug, string $cc, ?int $level): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r = (new Region())->setSlug($slug)->setName(ucfirst($slug))
            ->setCountryCode($cc)->setAdminLevel($level)->setGeom(self::GEOM)->setAreaKm2(1000.0);
        $em->persist($r);
        $em->flush();

        return (int) $r->getId();
    }

    public function testListingShowsOnboardedCountriesAndHidesL2(): void
    {
        $client = static::createClient();
        $this->seedRegion('wallonia-t', 'BE', 4);
        $this->seedRegion('belgium-t', 'BE', 2);

        $crawler = $client->request('GET', '/regions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.country h3', 'Belgium');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('belgium-t', $html, 'L2 infrastructure rows never render');
        self::assertStringContainsString('/join/BE', $html, 'every country header carries the curator door');
        // The baked snapshot is gone from this page.
        self::assertStringNotContainsString('regions-data.js', $html);
    }

    public function testListingLocalizedPath(): void
    {
        $client = static::createClient();
        $this->seedRegion('wallonia-t', 'BE', 4);

        $client->request('GET', '/fr/regions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.country h3', 'Belgique');
    }
}
