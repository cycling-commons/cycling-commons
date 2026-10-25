<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The region page's drawing of its country carries the country's other
 * regions: each lights under the pointer, names itself and leads to its own
 * page (map-and-search.md §4.6). Out of the tab order, as the drawing is
 * aria-hidden. DAMA wraps each test in a rolled-back transaction.
 */
final class RegionShapeLinksTest extends WebTestCase
{
    public function testTheOtherRegionsOfTheCountryLeadToTheirPages(): void
    {
        $client = static::createClient();
        $this->region('pshape', 'Pshape', 2, -142.0, -40.0, 2.0);
        $this->region('pshape-a', 'Pshape A', 4, -142.0, -40.0, 1.0);
        $this->region('pshape-b', 'Pshape B', 4, -141.0, -40.0, 1.0);

        $crawler = $client->request('GET', '/regions/pshape-a');
        self::assertResponseIsSuccessful();
        $figure = $crawler->filter('figure.rshape');
        self::assertSame('true', $figure->attr('aria-hidden'));
        $links = $figure->filter('svg a');
        self::assertSame(['/regions/pshape-b'], $links->each(static fn ($a): string => (string) $a->attr('href')), 'the other region of the country, not this one nor the outline');
        self::assertSame('-1', $links->attr('tabindex'), 'out of the tab order');
        self::assertSame('Pshape B', $links->filter('title')->text(), 'it names itself under the pointer');
        self::assertCount(1, $links->filter('path.rg-sib'));
        self::assertCount(1, $figure->filter('svg > path.rg-in'), 'this region stays filled');
        self::assertCount(1, $figure->filter('svg > path.co-out.co-edge'), 'with its regions drawn, the outline keeps its edge only');
    }

    private function region(string $slug, string $name, int $level, float $x, float $y, float $size): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $ring = [[$x, $y], [$x + $size, $y], [$x + $size, $y + $size], [$x, $y + $size], [$x, $y]];
        $region = (new Region())->setSlug($slug)->setName($name)->setCountryCode('PN')->setAdminLevel($level)->setAreaKm2(1.0)
            ->setGeom((string) json_encode(['type' => 'MultiPolygon', 'coordinates' => [[$ring]]]));
        $em->persist($region);
        $em->flush();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET outline = CAST(:o AS jsonb) WHERE id = :id', [
            'o' => json_encode([array_merge(...$ring)], \JSON_THROW_ON_ERROR),
            'id' => $region->getId(),
        ]);
    }
}
