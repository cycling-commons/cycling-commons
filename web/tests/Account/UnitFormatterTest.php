<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\DistanceUnit;
use App\Account\ElevationUnit;
use App\Account\UnitFormatter;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Distances and heights written in the units the reader asked for
 * (docs/specs/account-and-auth.md §9).
 *
 * Built directly rather than pulled from the container: the whole behaviour is
 * a function of one input — who is signed in — and constructing that explicitly
 * is what makes each case readable.
 */
final class UnitFormatterTest extends TestCase
{
    private function formatter(?DistanceUnit $distance, ?ElevationUnit $elevation = null): UnitFormatter
    {
        // A stub, not a mock: nothing here verifies HOW Security is called,
        // only what it hands back, and phpunit.dist.xml fails the run on the
        // notice an expectation-less mock raises.
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(
            null === $distance && null === $elevation
                ? null
                : (new User())
                    ->setDistanceUnit($distance ?? DistanceUnit::Km)
                    ->setElevationUnit($elevation ?? ElevationUnit::M),
        );

        return new UnitFormatter($security);
    }

    public function testKilometresAreLeftAloneAndMilesAreConverted(): void
    {
        self::assertSame('84.2 km', $this->formatter(DistanceUnit::Km)->distance(84.2));
        self::assertSame('52.3 mi', $this->formatter(DistanceUnit::Mi)->distance(84.2));
    }

    /** A round number reads as a round number: the decimal is not decoration. */
    public function testARoundDistanceKeepsNoTrailingZero(): void
    {
        self::assertSame('12 km', $this->formatter(DistanceUnit::Km)->distance(12.0));
        self::assertSame('1,240 m', $this->formatter(DistanceUnit::Km)->elevation(1240));
    }

    public function testClimbingIsWrittenInFeetWhenAskedFor(): void
    {
        self::assertSame('1,240 m', $this->formatter(null, ElevationUnit::M)->elevation(1240));
        self::assertSame('4,068 ft', $this->formatter(null, ElevationUnit::Ft)->elevation(1240));
    }

    /**
     * The two preferences are independent, which is the entire reason they are
     * two columns: miles with metres of climbing is what most of Britain rides.
     */
    public function testMilesAndMetresIsAnAllowedCombination(): void
    {
        $mixed = $this->formatter(DistanceUnit::Mi, ElevationUnit::M);

        self::assertSame('62.1 mi', $mixed->distance(100));
        self::assertSame('1,000 m', $mixed->elevation(1000));
    }

    /**
     * A short horizontal distance follows the DISTANCE preference and lands in
     * feet, not in fractions of a mile: "820 ft off the track" is a distance
     * somebody can picture, "0.16 mi" is not.
     */
    public function testShortDistancesUseFeetForAMilesRider(): void
    {
        self::assertSame('250 m', $this->formatter(DistanceUnit::Km)->shortDistance(250));
        self::assertSame('820 ft', $this->formatter(DistanceUnit::Mi)->shortDistance(250));
    }

    /** Past a quarter mile the short form stops being readable and promotes itself. */
    public function testAShortDistanceLongEnoughToRidePromotesToTheLongForm(): void
    {
        self::assertSame('1.5 km', $this->formatter(DistanceUnit::Km)->shortDistance(1500));
        self::assertSame('0.9 mi', $this->formatter(DistanceUnit::Mi)->shortDistance(1500));
    }

    public function testAreasAndDensitiesMoveInOppositeDirections(): void
    {
        $mi = $this->formatter(DistanceUnit::Mi);

        // A square mile is bigger than a square kilometre, so the same country
        // covers FEWER of them...
        self::assertSame('6,212 sq mi', $mi->area(16_089));
        // ...and each one holds MORE places.
        self::assertSame('2.59', $mi->density(1.0));
        self::assertSame('1.00', $this->formatter(DistanceUnit::Km)->density(1.0));
    }

    /** Nobody signed in has no preference to read, and metric is what we store. */
    public function testAnonymousVisitorsGetMetric(): void
    {
        $anon = $this->formatter(null, null);

        self::assertSame('84.2 km', $anon->distance(84.2));
        self::assertSame('1,240 m', $anon->elevation(1240));
        self::assertSame(DistanceUnit::Km, $anon->distanceUnit());
        self::assertSame(ElevationUnit::M, $anon->elevationUnit());
    }

    /** A missing number prints nothing rather than "0 km", which would be a claim. */
    public function testAMissingValuePrintsNothing(): void
    {
        $f = $this->formatter(DistanceUnit::Mi, ElevationUnit::Ft);

        self::assertSame('', $f->distance(null));
        self::assertSame('', $f->elevation(null));
        self::assertSame('', $f->shortDistance(''));
        self::assertSame('', $f->area(null));
    }

    /**
     * The round trip a typed field depends on: what the rider sees in their own
     * unit has to come back as the metric value we store.
     */
    public function testTypedValuesRoundTripBackToMetric(): void
    {
        $shown = DistanceUnit::Mi->fromKm(4.3);
        self::assertEqualsWithDelta(4.3, DistanceUnit::Mi->toKm($shown), 0.0001);

        $climb = ElevationUnit::Ft->fromMetres(161.0);
        self::assertEqualsWithDelta(161.0, ElevationUnit::Ft->toMetres($climb), 0.0001);

        // The metric settings are identities, not near-misses.
        self::assertSame(4.3, DistanceUnit::Km->toKm(4.3));
        self::assertSame(161.0, ElevationUnit::M->toMetres(161.0));
    }
}
