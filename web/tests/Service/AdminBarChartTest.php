<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AdminBarChart;
use PHPUnit\Framework\TestCase;

/**
 * The admin's column charts: a scale of four clean steps, compact ticks,
 * stacked segments with a 2 px gap and only the top one rounded, columns at
 * most 24 px, and a limit line only once the data come within half of it.
 */
final class AdminBarChartTest extends TestCase
{
    public function testTheScaleIsFourCleanStepsOfAtLeastOne(): void
    {
        self::assertSame(4.0, AdminBarChart::niceTop(1.0), 'a count of one reads 0, 1, 2, 3, 4');
        self::assertSame(4.0, AdminBarChart::niceTop(3.0));
        self::assertSame(8.0, AdminBarChart::niceTop(4.5));
        self::assertSame(100.0, AdminBarChart::niceTop(100.0));
        self::assertSame(10000.0, AdminBarChart::niceTop(10000.0));
    }

    public function testTicksAreCompactFromAThousand(): void
    {
        self::assertSame(['0', '2.5', '250', '1.5K', '7.5K', '10K'], array_map(AdminBarChart::compact(...), [0.0, 2.5, 250.0, 1500.0, 7500.0, 10000.0]));
    }

    public function testSeriesStackWithAGapAndOnlyTheTopSegmentRounded(): void
    {
        $c = AdminBarChart::build(['a', 'b'], ['sent' => [2.0, 0.0], 'cache' => [1.0, 3.0]]);

        self::assertSame(['0', '1', '2', '3', '4'], array_column($c['ticks'], 'label'));
        self::assertSame(24.0, $c['bars'][0]['w'], 'a column is at most 24 px wide');
        self::assertSame(['sent', 'cache'], array_column($c['bars'][0]['segments'], 'series'));
        self::assertSame('M198 194H222V106H198Z', $c['bars'][0]['segments'][0]['d'], 'the lower segment is square');
        self::assertSame('M198 104V66Q198 62 202 62H218Q222 62 222 66V104Z', $c['bars'][0]['segments'][1]['d'], 'the top one starts 2 px above it, its top rounded 4 px');
        self::assertSame(['cache'], array_column($c['bars'][1]['segments'], 'series'), 'an empty series draws nothing');
        self::assertNull($c['limit']);
    }

    public function testTheLimitLineShowsOnlyWithinHalfOfIt(): void
    {
        $far = AdminBarChart::build(['a'], ['counted' => [100.0]], 10000.0);
        self::assertNull($far['limit'], 'a far-off limit does not flatten the columns');
        self::assertSame('100', end($far['ticks'])['label']);

        $near = AdminBarChart::build(['a'], ['counted' => [6000.0]], 10000.0);
        self::assertSame(['y' => 18.0, 'value' => 10000.0], $near['limit']);
        self::assertSame(6000.0, $near['max']);
    }

    public function testAtMostEightLabelsEndingOnTheLastBand(): void
    {
        $labels = array_map(static fn (int $h): string => sprintf('%02d:00', $h % 24), range(0, 47));

        $c = AdminBarChart::build($labels, ['counted' => array_fill(0, 48, 1.0)]);

        self::assertCount(8, $c['xLabels']);
        self::assertSame('23:00', end($c['xLabels'])['text']);
    }
}
