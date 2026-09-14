<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\World;

use App\World\CuratorScopes;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which areas can be asked for: held, not yet onboarded, and big enough.
 *
 * Seeded rather than read from a loaded world, so each rule is pinned by the
 * rows that exercise it and the suite does not depend on an Overture import.
 *
 * @see docs/specs/moderation-and-contribution.md §11.1a
 */
final class CuratorScopesTest extends KernelTestCase
{
    private function scopes(): CuratorScopes
    {
        self::bootKernel();

        return self::getContainer()->get(CuratorScopes::class);
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function division(string $cc, ?string $iso, string $name, float $areaKm2): void
    {
        $this->db()->executeStatement(
            "INSERT INTO world_division (country_code, iso_code, name, subtype, geom, area_km2, source, created_at, updated_at)
             VALUES (:cc, :iso, :name, 'region', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), :area, 'test', NOW(), NOW())",
            ['cc' => $cc, 'iso' => $iso, 'name' => $name, 'area' => $areaKm2],
        );
    }

    public function testAHeldDivisionIsOffered(): void
    {
        $scopes = $this->scopes();
        $this->division('XA', 'XA-OH', 'Ohio', 116000.0);
        $this->division('XA', 'XA-TX', 'Texas', 695000.0);

        self::assertSame(['Ohio', 'Texas'], $scopes->forCountry('XA'));
    }

    /**
     * Slovenia's 212 municipalities have a median of 65 km², Luxembourg's
     * cantons 218, Switzerland's cantons 883. The line is 500 km², which is
     * the call tools/divisions/config.py had made by hand.
     */
    public function testACountryOfDivisionsTooSmallToCurateOffersOnlyItself(): void
    {
        $scopes = $this->scopes();
        foreach (['A' => 40.0, 'B' => 65.0, 'C' => 90.0] as $k => $area) {
            $this->division('XB', 'XB-'.$k, 'Municipality '.$k, $area);
        }

        self::assertSame([], $scopes->forCountry('XB'));
    }

    public function testSmallCantonsStillCountWhenTheMedianClearsTheLine(): void
    {
        $scopes = $this->scopes();
        // One city canton of 37 km², as Basel-Stadt is, among cantons that are
        // not: the median decides, so it does not take the country down with it.
        $this->division('XC', 'XC-BS', 'Basel-Stadt', 37.0);
        $this->division('XC', 'XC-BE', 'Bern', 5959.0);
        $this->division('XC', 'XC-GR', 'Graubünden', 7105.0);

        self::assertSame(['Basel-Stadt', 'Bern', 'Graubünden'], $scopes->forCountry('XC'));
    }

    /**
     * Matched by ISO code, because the names never agree: the map says
     * "Bavaria", the boundary data "Bayern". By name, 61 live regions were
     * offered again as not yet on the Commons (2026-09-14).
     */
    public function testAnOnboardedDivisionIsLeftOutByCodeWhateverItIsCalled(): void
    {
        $scopes = $this->scopes();
        $this->division('XD', 'XD-BY', 'Bayern', 70550.0);
        $this->division('XD', 'XD-HE', 'Hessen', 21115.0);
        $this->db()->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('xd-bavaria-test', 'Bavaria', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 70550, 'XD', 'XD-BY', 4, 'test', NOW(), NOW())",
        );

        self::assertSame(['Hessen'], $scopes->forCountry('XD'));
    }

    public function testADivisionWithNoIsoCodeIsNotOffered(): void
    {
        $scopes = $this->scopes();
        // Overture ships the uninhabited ones without a code (Coral Sea
        // Islands, Plazas de Soberanía), and without one nothing could tell
        // them apart from a live region.
        $this->division('XE', null, 'Coral Sea Islands', 3.0);
        $this->division('XE', 'XE-NSW', 'New South Wales', 800000.0);

        self::assertSame(['New South Wales'], $scopes->forCountry('XE'));
    }

    public function testTheWholeSetIsTaggedWithTheCountryItBelongsTo(): void
    {
        $scopes = $this->scopes();
        $this->division('XF', 'XF-1', 'Alpha', 20000.0);
        $this->division('XG', 'XG-1', 'Beta', 30000.0);

        self::assertSame(
            [['name' => 'Alpha', 'cc' => 'XF'], ['name' => 'Beta', 'cc' => 'XG']],
            $scopes->forCountries(['XF', 'XG']),
        );
    }

    public function testNothingLoadedMeansOnlyCountries(): void
    {
        self::assertSame([], $this->scopes()->forCountry('XZ'));
    }
}
