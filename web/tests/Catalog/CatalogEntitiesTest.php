<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\HeatPoint;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogEntitiesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeItem(string $ref): Item
    {
        return (new Item())
            ->setLetter('D')
            ->setName('Test pump')
            ->setGeom('{"type":"Point","coordinates":[4.5,50.5]}')
            ->setCountryCode('BE')
            ->setState(ItemState::Unverified)
            ->setSource(ItemSource::Osm)
            ->setSourceRef($ref)
            ->setAttributes(['t' => 'Pump']);
    }

    public function testItemPersistsAndReloadsWithEnumsAndJsonb(): void
    {
        $this->em->persist($this->makeItem('node/1'));
        $this->em->flush();
        $this->em->clear();

        $item = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1']);
        self::assertNotNull($item);
        self::assertSame(ItemState::Unverified, $item->getState());
        self::assertSame(ItemSource::Osm, $item->getSource());
        self::assertSame(['t' => 'Pump'], $item->getAttributes());
        self::assertStringContainsString('"Point"', (string) $item->getGeom());
        self::assertNotNull($item->getImportedAt());
    }

    public function testDuplicateSourceRefIsRejected(): void
    {
        $this->em->persist($this->makeItem('node/2'));
        $this->em->flush();
        $this->em->persist($this->makeItem('node/2'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testRouteAndHeatPersist(): void
    {
        $route = (new RecommendedRoute())
            ->setName('Test loop')
            ->setGeom('{"type":"LineString","coordinates":[[4.5,50.5],[4.6,50.6]]}')
            ->setDistanceM(12300)->setAscentM(210)
            ->setState(ItemState::Unverified)->setSource(ItemSource::Auto)->setSourceRef('fx:route:test-loop')
            ->setAttributes(['season' => 'summer']);
        $heat = (new HeatPoint())
            ->setGeom('{"type":"Point","coordinates":[4.5,50.5]}')
            ->setWeight(1.0)->setSource(ItemSource::Auto);
        $this->em->persist($route);
        $this->em->persist($heat);
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull($this->em->getRepository(RecommendedRoute::class)->findOneBy(['sourceRef' => 'fx:route:test-loop']));
        self::assertCount(1, $this->em->getRepository(HeatPoint::class)->findAll());
    }
}
