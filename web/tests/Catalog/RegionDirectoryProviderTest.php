<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use App\Catalog\RegionDirectoryProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegionDirectoryProviderTest extends KernelTestCase
{
    private const GEOM = '{"type":"MultiPolygon","coordinates":[[[[4,50],[5,50],[5,51],[4,51],[4,50]]]]}';

    private function seedRegion(string $slug, string $cc, ?int $level, bool $curated = false): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r = (new Region())->setSlug($slug)->setName(ucfirst($slug))
            ->setCountryCode($cc)->setAdminLevel($level)->setGeom(self::GEOM)->setAreaKm2(1000.0);
        $em->persist($r);
        $em->flush();
        if ($curated) {
            static::getContainer()->get(Connection::class)
                ->executeStatement('UPDATE region SET curated_default = TRUE WHERE id = ?', [$r->getId()]);
        }

        return (int) $r->getId();
    }

    private function seedItem(int $regionId, string $letter, string $state): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (?, 'T', ST_SetSRID(ST_GeomFromText('POINT(4.5 50.5)'), 4326), 'BE', ?, ?, 'seed', ?, '{}', NOW(), NOW())",
            [$letter, $regionId, $state, uniqid('t', true)],
        );
    }

    public function testDirectoryTiersCountsAndL2Exclusion(): void
    {
        self::bootKernel();
        $wal = $this->seedRegion('wallonia-t', 'BE', 4, curated: true);
        $vlg = $this->seedRegion('flanders-t', 'BE', 4);
        $this->seedRegion('empty-t', 'BE', 4);
        $this->seedRegion('belgium-t', 'BE', 2);
        $this->seedItem($wal, 'B', 'verified');
        $this->seedItem($wal, 'B', 'unverified'); // must NOT count
        $this->seedItem($vlg, 'C', 'verified');

        $provider = static::getContainer()->get(RegionDirectoryProvider::class);
        $countries = $provider->directory('en');
        $be = array_values(array_filter($countries, static fn (array $c): bool => 'BE' === $c['code']));
        self::assertCount(1, $be);
        self::assertSame('Belgium', $be[0]['name']);
        self::assertSame('flags/be.svg', $be[0]['flag']);

        $bySlug = array_column($be[0]['regions'], null, 'slug');
        self::assertArrayNotHasKey('belgium-t', $bySlug);
        self::assertSame('curated', $bySlug['wallonia-t']['tier']);
        self::assertSame(1, $bySlug['wallonia-t']['itemsVerified'], 'unverified items must not count');
        self::assertSame('growing', $bySlug['flanders-t']['tier']);
        self::assertSame('onboarded', $bySlug['empty-t']['tier'], 'no curated flag, no verified items');
    }

    public function testDetailByKindAndOperationalGate(): void
    {
        self::bootKernel();
        $wal = $this->seedRegion('wallonia-t', 'BE', 4);
        $this->seedRegion('belgium-t', 'BE', 2);
        $this->seedItem($wal, 'B', 'verified');
        $this->seedItem($wal, 'C', 'verified');
        $this->seedItem($wal, 'C', 'verified');

        $provider = static::getContainer()->get(RegionDirectoryProvider::class);
        $detail = $provider->region('wallonia-t', 'fr');
        self::assertNotNull($detail);
        self::assertSame('BE', $detail['countryCode']);
        self::assertSame('Belgique', $detail['countryName']);
        self::assertSame(3, $detail['itemsVerified']);
        $kinds = array_column($detail['byKind'], 'count', 'labelKey');
        self::assertSame(1, $kinds['item_type.climbs.label']);
        self::assertSame(2, $kinds['item_type.water-food.label']);

        self::assertNull($provider->region('belgium-t', 'en'), 'infrastructure rows have no page');
        self::assertNull($provider->region('nope', 'en'));
    }

    /**
     * strcoll sorts by raw byte order under the process locale and misplaces
     * accented names to the end (e.g. "Éthiopie", "Île-de-France" landing
     * after "Zambie"); \Collator::compare() sorts by the request locale's
     * actual collation rules instead.
     */
    public function testDirectoryOrdersLocalizedCountryNamesByCollationNotByteOrder(): void
    {
        self::bootKernel();
        // Both African, so they land in the same continent group. In French,
        // "Éthiopie" collates before "Zambie" alphabetically; under raw
        // byte-order strcoll, the accented name sorts after every
        // plain-ASCII name instead — the opposite order.
        $this->seedRegion('ethiopia-collate-t', 'ET', 4);
        $this->seedRegion('zambia-collate-t', 'ZM', 4);

        $provider = static::getContainer()->get(RegionDirectoryProvider::class);
        $countries = $provider->directory('fr');
        $africa = array_values(array_filter($countries, static fn (array $c): bool => 'Africa' === $c['continentName']));
        $names = array_column($africa, 'name');

        self::assertContains('Éthiopie', $names);
        self::assertContains('Zambie', $names);
        self::assertLessThan(
            array_search('Zambie', $names, true),
            array_search('Éthiopie', $names, true),
            'proper collation must place "Éthiopie" before "Zambie", not after it as byte-order strcoll would',
        );
    }
}
