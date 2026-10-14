<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Catalog\BikeType;
use App\Elevation\BikeCosting;
use PHPUnit\Framework\TestCase;

/**
 * The one place a Commons bike type becomes the routing engine's bicycle
 * costing (docs/specs/route-planner.md section 4.2).
 *
 * The engine does not refuse an unknown `bicycle_type`: it silently rides its
 * default and the route moves by a few per cent. So every type has a block
 * here, and the block names one of the four types the engine knows.
 */
final class BikeCostingTest extends TestCase
{
    public function testEveryBikeTypeHasACostingBlockTheEngineKnows(): void
    {
        foreach (BikeType::cases() as $bike) {
            $options = BikeCosting::options($bike);
            self::assertArrayHasKey('bicycle_type', $options, $bike->value);
            self::assertContains($options['bicycle_type'], ['Road', 'Hybrid', 'Cross', 'Mountain'], $bike->value);
        }
    }

    public function testTheThreeEngineProfilesMapOneToOne(): void
    {
        self::assertSame(['bicycle_type' => 'Road'], BikeCosting::options(BikeType::Road));
        self::assertSame(['bicycle_type' => 'Cross'], BikeCosting::options(BikeType::Gravel));
        self::assertSame(['bicycle_type' => 'Mountain'], BikeCosting::options(BikeType::Mtb));
    }

    public function testAnEbikeRidesHybridAndDoesNotMindHills(): void
    {
        self::assertSame(['bicycle_type' => 'Hybrid', 'use_hills' => 1.0], BikeCosting::options(BikeType::Ebike));
    }

    public function testAHandbikeAvoidsHillsAndRoughSurfaces(): void
    {
        self::assertSame(
            ['bicycle_type' => 'Road', 'use_hills' => 0.0, 'avoid_bad_surfaces' => 1.0],
            BikeCosting::options(BikeType::Handbike),
        );
    }

    public function testLongOrWideBikesPaySteepAndRoughRoads(): void
    {
        $expected = ['bicycle_type' => 'Road', 'use_hills' => 0.2, 'avoid_bad_surfaces' => 0.8];
        self::assertSame($expected, BikeCosting::options(BikeType::Recumbent));
        self::assertSame($expected, BikeCosting::options(BikeType::Trike));
        self::assertSame($expected, BikeCosting::options(BikeType::Tandem));
    }
}
