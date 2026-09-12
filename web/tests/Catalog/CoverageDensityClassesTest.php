<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CoverageStatsProvider;
use PHPUnit\Framework\TestCase;

/**
 * The globe on /coverage paints each country by a density class,
 * five equal-count steps of one hue. The steps are decided here, on the
 * server, so the legend and the paint come from one computation.
 */
final class CoverageDensityClassesTest extends TestCase
{
    /** @param list<array{code: string, density: float}> $rows */
    private static function classes(array $rows): array
    {
        $countries = [];
        foreach ($rows as $row) {
            $countries[] = ['code' => $row['code'], 'poisPerKm2' => $row['density']];
        }

        return CoverageStatsProvider::densityClasses($countries);
    }

    public function testTenCountriesFallIntoFiveEqualSteps(): void
    {
        $rows = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $i => $code) {
            $rows[] = ['code' => $code, 'density' => ($i + 1) * 0.5];
        }
        $out = self::classes($rows);

        self::assertCount(5, $out['bounds']);
        self::assertSame(1, $out['byCode']['A']);
        self::assertSame(1, $out['byCode']['B']);
        self::assertSame(3, $out['byCode']['E']);
        self::assertSame(5, $out['byCode']['J']);
        self::assertSame(['min' => 0.5, 'max' => 1.0], $out['bounds'][0]);
        self::assertSame(['min' => 4.5, 'max' => 5.0], $out['bounds'][4]);
    }

    public function testAcountryWithNoPoisIsClassZeroAndOutsideTheBounds(): void
    {
        $out = self::classes([
            ['code' => 'RW', 'density' => 0.0],
            ['code' => 'LU', 'density' => 3.2],
            ['code' => 'NL', 'density' => 2.1],
        ]);

        self::assertSame(0, $out['byCode']['RW']);
        self::assertSame(1, $out['byCode']['NL']);
        self::assertSame(2, $out['byCode']['LU']);
        // Two countries with data make two steps, not five with three empty.
        self::assertCount(2, $out['bounds']);
        self::assertSame(2.1, $out['bounds'][0]['min']);
    }

    public function testNoDataAtAllMakesNoSteps(): void
    {
        $out = self::classes([['code' => 'RW', 'density' => 0.0]]);

        self::assertSame([], $out['bounds']);
        self::assertSame(['RW' => 0], $out['byCode']);
    }

    public function testEqualDensitiesShareAStepAndTheOrderIsTotal(): void
    {
        // Same density, so the country code decides the rank; called twice
        // with the rows shuffled, the answer must not move.
        $a = self::classes([['code' => 'BE', 'density' => 1.0], ['code' => 'NL', 'density' => 1.0], ['code' => 'DE', 'density' => 1.0]]);
        $b = self::classes([['code' => 'NL', 'density' => 1.0], ['code' => 'DE', 'density' => 1.0], ['code' => 'BE', 'density' => 1.0]]);

        self::assertSame($a, $b);
        self::assertCount(3, $a['bounds']);
    }
}
