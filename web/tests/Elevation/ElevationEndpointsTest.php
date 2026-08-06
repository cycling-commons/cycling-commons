<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\ElevationEndpoints;
use PHPUnit\Framework\TestCase;

/**
 * Which Valhalla gets asked for a climb's ground.
 *
 * Each instance serves only the .hgt tiles in its own directory and answers
 * zeros everywhere else, so this routing decides whether a climb outside Europe
 * gets a profile at all (climb-elevation.md §2b-i).
 */
final class ElevationEndpointsTest extends TestCase
{
    private const string MAP = 'north-america=http://na.test,asia=http://asia.test,oceania=http://oc.test';

    private function endpoints(string $map = self::MAP): ElevationEndpoints
    {
        return new ElevationEndpoints('http://europe.test', $map);
    }

    public function testAnUnsetMapSendsEverythingToTheOneInstance(): void
    {
        // The behaviour before per-continent instances existed, and still the
        // behaviour of any deployment that has not configured them.
        $e = $this->endpoints('');

        self::assertSame('http://europe.test', $e->forPoint(50.48, 5.70));   // La Redoute
        self::assertSame('http://europe.test', $e->forPoint(-37.81, 144.96)); // Melbourne
    }

    public function testEachOnboardedCountryReachesTheInstanceHoldingItsTiles(): void
    {
        $e = $this->endpoints();

        // Europe keeps the default instance: FR/CH/GB/IT are all inside the
        // EUROPE tile set that is already loaded there.
        self::assertSame('http://europe.test', $e->forPoint(45.05, 6.39));   // Col du Galibier, FR
        self::assertSame('http://europe.test', $e->forPoint(46.50, 8.00));   // Furka, CH
        self::assertSame('http://europe.test', $e->forPoint(54.42, -3.06));  // Hardknott, GB
        self::assertSame('http://europe.test', $e->forPoint(46.51, 11.85));  // Sella, IT

        self::assertSame('http://oc.test', $e->forPoint(-37.70, 145.35));    // Mt Donna Buang, AU
        self::assertSame('http://asia.test', $e->forPoint(35.36, 138.73));   // Fuji Subaru Line, JP
        self::assertSame('http://na.test', $e->forPoint(37.88, -122.24));    // Berkeley Hills, US-CA
        self::assertSame('http://na.test', $e->forPoint(39.12, -106.56));    // Independence Pass, US-CO
    }

    public function testEuropeWinsWhereItsTilesOverlapAfricasBox(): void
    {
        // Sicily and southern Spain sit inside a true Africa bounding box, but
        // their tiles are in the EUROPE set. Priority order, not geography, is
        // what has to be right here.
        $e = $this->endpoints('africa=http://africa.test');

        self::assertSame('http://europe.test', $e->forPoint(37.75, 14.99));  // Etna, IT
        self::assertSame('http://europe.test', $e->forPoint(37.05, -3.39));  // Veleta, ES
        self::assertSame('http://africa.test', $e->forPoint(31.06, -7.92));  // Tizi n'Test, MA
    }

    public function testAContinentWithNoConfiguredInstanceFallsBackRatherThanBlackHoling(): void
    {
        // south-america has no entry in MAP, so a Chilean climb goes to the
        // default instead of vanishing. It will answer zeros and the client will
        // reject them — a missing profile, never a wrong one.
        $e = $this->endpoints();

        self::assertSame('http://europe.test', $e->forPoint(-33.35, -70.35));
    }

    public function testTheShapeIsRoutedByItsFirstPoint(): void
    {
        $e = $this->endpoints();

        self::assertSame('http://asia.test', $e->forShape([[35.36, 138.73], [35.37, 138.74]]));
        self::assertSame('http://europe.test', $e->forShape([]));
    }

    public function testAMalformedOrUnknownEntryIsDroppedRatherThanTrusted(): void
    {
        // A typo'd continent key must not look configured: it would match no box
        // and silently disable routing for the continent it was meant to serve.
        $e = $this->endpoints('occeania=http://typo.test,asia,=http://noKey.test,asia=http://asia.test');

        self::assertSame('http://europe.test', $e->forPoint(-37.70, 145.35));
        self::assertSame('http://asia.test', $e->forPoint(35.36, 138.73));
    }
}
