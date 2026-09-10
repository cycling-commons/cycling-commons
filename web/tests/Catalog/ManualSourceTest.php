<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogProvider;
use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase C1-T1: `manual` is a new ItemSource for hand-added/seeded demo pins,
 * treated like a rider contribution — it must resolve like any other source
 * and be served by CatalogProvider exactly like an `osm` item in the same
 * state (source-agnostic serving outside letter O's osm/authority split).
 */
final class ManualSourceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testManualResolvesAsItemSource(): void
    {
        self::assertSame(ItemSource::Manual, ItemSource::from('manual'));
    }

    public function testManualSourceItemIsServedLikeOsmItem(): void
    {
        $osm = (new Item())
            ->setLetter('D')
            ->setName('Test osm pump')
            ->setGeom('{"type":"Point","coordinates":[4.5,50.5]}')
            ->setCountryCode('BE')
            ->setState(ItemState::Unverified)
            ->setSource(ItemSource::Osm)
            ->setSourceRef('node/manual-src-test-osm')
            ->setAttributes(['t' => 'Pump']);
        $manual = (new Item())
            ->setLetter('D')
            ->setName('Test manual pump')
            ->setGeom('{"type":"Point","coordinates":[4.6,50.6]}')
            ->setCountryCode('BE')
            ->setState(ItemState::Unverified)
            ->setSource(ItemSource::Manual)
            ->setSourceRef('fx:manual-src-test-manual')
            ->setAttributes(['t' => 'Pump']);
        $this->em->persist($osm);
        $this->em->persist($manual);
        $this->em->flush();

        // Coverage retirement (coverage-provider.md §9): an
        // untouched source=osm/unverified row no longer serves from
        // catalog.json (it lives in coverage_poi now) — a human touch (here: a
        // confirmation) keeps it canonical without changing its state, so this
        // test still compares manual vs. osm serving in the SAME state.
        $this->em->getConnection()->executeStatement(
            'INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at) VALUES (:item, 1, :stance, NOW(), NOW())',
            ['item' => $osm->getId(), 'stance' => 'exists'],
        );

        $json = static::getContainer()->get(CatalogProvider::class)->json();
        self::assertStringContainsString('Test osm pump', $json);
        self::assertStringContainsString('Test manual pump', $json);

        $features = static::getContainer()->get(CatalogProvider::class)->payload()['D']['features'];
        $names = array_map(static fn (array $f): ?string => $f['properties']['n'] ?? null, $features);
        self::assertContains('Test osm pump', $names);
        self::assertContains('Test manual pump', $names, 'manual-source item must be served like any other served-state item');
    }
}
