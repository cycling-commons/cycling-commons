<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\RegionUpserter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegionUpserterTest extends KernelTestCase
{
    private const string SQUARE = '{"type":"MultiPolygon","coordinates":[[[[30,30],[31,30],[31,31],[30,31],[30,30]]]]}';

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** @return array<string, mixed> */
    private static function props(): array
    {
        return ['slug' => 'ups-square', 'name' => 'Square', 'area_km2' => 12000, 'country_code' => 'xa',
            'iso_code' => 'XA-SQ', 'admin_level' => 4, 'source' => 'overture'];
    }

    public function testLabelsAreWrittenAndKeptWhenNoneArePassed(): void
    {
        self::bootKernel();
        $upserter = static::getContainer()->get(RegionUpserter::class);
        $upserter->upsert(self::props(), self::SQUARE, 'test', ['en' => 'Square', 'fr' => 'Carré']);
        $upserter->upsert(self::props(), self::SQUARE, 'test');

        $row = $this->db()->fetchAssociative("SELECT country_code, labels->>'fr' AS fr FROM region WHERE slug = 'ups-square'");
        self::assertSame(['country_code' => 'XA', 'fr' => 'Carré'], $row);
    }

    public function testAnEmptyLabelMapNeverReplacesStoredLabels(): void
    {
        self::bootKernel();
        $upserter = static::getContainer()->get(RegionUpserter::class);
        $upserter->upsert(self::props(), self::SQUARE, 'test', ['fr' => 'Carré']);
        $upserter->upsert(self::props(), self::SQUARE, 'test', []);

        self::assertSame('Carré', $this->db()->fetchOne("SELECT labels->>'fr' FROM region WHERE slug = 'ups-square'"));
    }

    public function testAMissingCountryCodeIsRefused(): void
    {
        self::bootKernel();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plan XA/ups-square: region artifact missing required 2-letter country_code');
        static::getContainer()->get(RegionUpserter::class)
            ->upsert(['country_code' => ''] + self::props(), self::SQUARE, 'plan XA/ups-square');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badProps(): iterable
    {
        yield 'slug missing' => [['slug' => null] + self::props(), 'missing required slug'];
        yield 'name missing' => [['name' => ''] + self::props(), 'missing required name'];
        yield 'area not numeric' => [['area_km2' => 'big'] + self::props(), 'area_km2 is not numeric'];
    }

    /** @param array<string, mixed> $props */
    #[\PHPUnit\Framework\Attributes\DataProvider('badProps')]
    public function testMalformedPropertiesAreRefused(array $props, string $message): void
    {
        self::bootKernel();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        static::getContainer()->get(RegionUpserter::class)->upsert($props, self::SQUARE, 'file.geojson');
    }
}
