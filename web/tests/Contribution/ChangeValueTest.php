<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Contribution\ChangeValue;
use PHPUnit\Framework\TestCase;

/**
 * A changed value as a human reads it.
 *
 * The geometry cases are the reason this exists: a curator was shown two
 * hundred raw coordinate pairs and could not tell from them that the climb had
 * got a kilometre longer.
 */
final class ChangeValueTest extends TestCase
{
    /** La Redoute's first few nodes — a real fragment, not invented numbers. */
    private const array ROUTE = [
        [50.48321, 5.70391], [50.48327, 5.70383], [50.4837, 5.70348],
        [50.48396, 5.70319], [50.48421, 5.70315], [50.48453, 5.70322],
    ];

    public function testARouteReadsAsItsLengthAndItsEnds(): void
    {
        $out = ChangeValue::format('route', self::ROUTE);

        self::assertStringContainsString('km', $out);
        self::assertStringContainsString('foot 50.4832, 5.7039', $out);
        self::assertStringContainsString('summit 50.4845, 5.7032', $out);
        self::assertStringContainsString('6 points', $out);
        self::assertStringNotContainsString('[[', $out, 'no raw JSON reaches a curator');
    }

    /** The number that matters: a redrawn climb is longer or shorter. */
    public function testTheLengthIsRealDistanceNotAPointCount(): void
    {
        // ~1 degree of latitude is ~111 km; a tenth of that is ~11 km.
        $out = ChangeValue::format('route', [[50.0, 5.0], [50.1, 5.0]]);

        self::assertMatchesRegularExpression('/^11\.\d km/', $out);
    }

    public function testAGradientProfileReadsAsItsRange(): void
    {
        $out = ChangeValue::format('grad', [4, 6, 8, 11, 14, 18, 20, 16, 12, 9, 7, 8]);

        self::assertSame('12 samples · 4% to 20%', $out);
    }

    public function testTheSteepestMarkerKeepsItsPercentageAndPosition(): void
    {
        $out = ChangeValue::format('steep', ['at' => [50.49077, 5.70583], 'pct' => '~20%']);

        self::assertSame('~20% at 50.4908, 5.7058', $out);
    }

    public function testAHandPlacedMarkerSaysSo(): void
    {
        $out = ChangeValue::format('steep', ['at' => [50.49077, 5.70583], 'pct' => '18%', 'manual' => true]);

        self::assertStringContainsString('placed by hand', $out);
    }

    public function testOrdinaryFieldsAreUntouched(): void
    {
        self::assertSame('Rough', ChangeValue::format('sq', 'Rough'));
        self::assertSame('13', ChangeValue::format('hairpins', '13'));
        self::assertSame('', ChangeValue::format('note', null));
    }

    public function testAMultiSelectStillReadsAsAListRatherThanJson(): void
    {
        self::assertSame(
            'Step-free, Handbike-friendly',
            ChangeValue::format('accessibility', ['Step-free', 'Handbike-friendly']),
        );
    }

    /**
     * A road-surface stretch reads as a shape, not as its coordinates.
     *
     * `segment` is {a, b, line}, and `line` is every vertex of the marked
     * stretch: sixty pairs for a dike road. Printed raw it fills the card twice
     * over, above a Before/After map switch already showing the same thing
     * (owner-reported 2026-08-31). Same treatment `route` has always had.
     */
    public function testAMarkedStretchReadsAsLengthAndEndsRatherThanSixtyPairs(): void
    {
        $out = ChangeValue::format('segment', [
            'a' => [5.107, 52.663],
            'b' => [5.120, 52.650],
            'line' => [[52.663, 5.107], [52.660, 5.112], [52.650, 5.120]],
        ]);

        self::assertStringContainsString('km', $out);
        self::assertStringContainsString('3 points', $out);
        self::assertStringNotContainsString('[[', $out, 'a coordinate list must never reach the card');
        self::assertLessThan(120, \strlen($out), 'the summary has to fit a card, not fill one');
    }

    /** Malformed geometry must degrade to something printable, never throw. */
    public function testMalformedGeometryDoesNotBlowUpTheCard(): void
    {
        self::assertIsString(ChangeValue::format('route', 'not a route'));
        self::assertIsString(ChangeValue::format('route', [['nope']]));
        self::assertIsString(ChangeValue::format('grad', ['x', 'y']));
        self::assertIsString(ChangeValue::format('steep', 'nope'));
        self::assertIsString(ChangeValue::format('steep', ['pct' => '20%']));
        self::assertIsString(ChangeValue::format('segment', 'not a segment'));
        self::assertIsString(ChangeValue::format('segment', ['a' => [1, 2]]));
    }
}
