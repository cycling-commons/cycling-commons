<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CountryTimezones;
use App\Catalog\RegionLabels;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegionLabelsTest extends KernelTestCase
{
    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function labels(): RegionLabels
    {
        return static::getContainer()->get(RegionLabels::class);
    }

    public function testARegionLabelFallsBackToEnglishThenToTheName(): void
    {
        self::bootKernel();
        $this->db()->executeStatement(
            "INSERT INTO region (slug, name, labels, country_code, created_at, updated_at) VALUES
             ('lbl-zealand', 'Sjælland', '{\"en\":\"Zealand\",\"fr\":\"Zélande\"}', 'XA', NOW(), NOW()),
             ('lbl-bare', 'Bare Name', '{}', 'XA', NOW(), NOW())",
        );

        self::assertSame('Zélande', $this->labels()->label('lbl-zealand', null, 'fr'));
        self::assertSame('Zealand', $this->labels()->label('lbl-zealand', null, 'nl'));
        self::assertSame('Bare Name', $this->labels()->label('lbl-bare', null, 'de'));
        self::assertSame('Fallback', $this->labels()->label('lbl-unknown', 'Fallback', 'en'));
        self::assertSame('lbl-unknown', $this->labels()->label('lbl-unknown', null, 'en'));
    }

    public function testAnEmptyListOfLabelsIsNoLabels(): void
    {
        self::bootKernel();
        $this->db()->executeStatement(
            "INSERT INTO region (slug, name, labels, country_code, created_at, updated_at) VALUES
             ('lbl-list', 'List Name', '[]', 'XA', NOW(), NOW())",
        );
        $this->db()->executeStatement(
            "INSERT INTO country (code, name, subtype, labels, status) VALUES ('XC', 'Listland', 'region', '[]', 'planned')",
        );

        self::assertSame('List Name', $this->labels()->label('lbl-list', null, 'fr'));
        self::assertSame('Listland', $this->labels()->countryLabel('XC', 'fr'));
    }

    public function testACountryPhraseFallsBackTheSameWay(): void
    {
        self::bootKernel();
        $this->db()->executeStatement(
            "INSERT INTO country (code, name, subtype, labels, status) VALUES
             ('XA', 'Testland', 'region', '{\"en\":\"All Testland\"}', 'planned'),
             ('XB', 'Bareland', 'region', '{}', 'planned')",
        );

        self::assertSame('All Germany', $this->labels()->countryLabel('DE', 'en'));
        self::assertSame('All Testland', $this->labels()->countryLabel('xa', 'fr'));
        self::assertSame('Bareland', $this->labels()->countryLabel('XB', 'en'));
        self::assertSame('ZZ', $this->labels()->countryLabel('ZZ', 'en'));
    }

    public function testTheTimezoneMapCoversSeededAndLiveCountriesOnly(): void
    {
        self::bootKernel();
        $this->db()->executeStatement("INSERT INTO country (code, name, subtype, timezones, status) VALUES ('DK', 'Denmark', 'region', '{Europe/Copenhagen}', 'planned')");
        $map = static::getContainer()->get(CountryTimezones::class)->map();

        self::assertSame('DE', $map['Europe/Berlin']);
        self::assertSame('ES', $map['Atlantic/Canary']);
        self::assertSame('GB', $map['Europe/Belfast'], 'the seeded aliases are served as stored');
        self::assertArrayNotHasKey('America/New_York', $map, 'only the zones stored for a country');
        self::assertArrayNotHasKey('Europe/Copenhagen', $map, 'a planned country gives no hint');
    }
}
