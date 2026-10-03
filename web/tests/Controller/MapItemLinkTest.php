<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A `?item=<id>` link to a place taken as it is from OpenStreetMap: the map
 * draws that place as its OpenStreetMap point, not as a catalog row, so the
 * page names the reference and the map opens it as a `?ref=` link would
 * (owner 2026-10-03: a ballot link to "Fort de Marchovelette" opened nothing;
 * map-and-search.md §8).
 */
final class MapItemLinkTest extends WebTestCase
{
    private function item(ItemSource $source, string $ref, ItemState $state = ItemState::Unverified): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('Q')->setName('Fort '.bin2hex(random_bytes(3)))
            ->setGeom('{"type":"Point","coordinates":[4.9,50.5]}')->setCountryCode('BE')
            ->setState($state)->setSource($source)->setSourceRef($ref)->setAttributes([]);
        $em->persist($item);
        $em->flush();

        return (int) $item->getId();
    }

    public function testAnOsmPlaceLinkNamesItsReference(): void
    {
        $client = static::createClient();
        $id = $this->item(ItemSource::Osm, 'way/62004412');

        $client->request('GET', '/map?item='.$id.'/fort-de-marchovelette');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('window.CC_ITEM_LINK_REF = "way/62004412";', (string) $client->getResponse()->getContent());
    }

    public function testAnythingElseNamesNoReference(): void
    {
        $client = static::createClient();
        $retired = $this->item(ItemSource::Osm, 'node/123', ItemState::Retired);

        foreach (['/map?item='.$retired, '/map?item=999999999', '/map?item=abc', '/map'] as $url) {
            $client->request('GET', $url);
            self::assertStringContainsString('window.CC_ITEM_LINK_REF = null;', (string) $client->getResponse()->getContent(), $url);
        }
    }
}
