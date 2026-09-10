<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeType;
use App\Catalog\RidingStyle;
use PHPUnit\Framework\TestCase;

final class RidingStyleTest extends TestCase
{
    public function testValuesReturnsAllSevenStylesInDeclarationOrder(): void
    {
        self::assertSame(
            ['Road', 'Gravel', 'Touring', 'Bikepacking', 'Trail', 'Urban', 'Leisure'],
            RidingStyle::values(),
        );
    }

    public function testContainsNoHardwareTypes(): void
    {
        // Hardware lives in BikeType; the style enum must never duplicate it
        // (spec: E-bike, Handbike, Recumbent, Trike, Tandem are bikes, not styles).
        $hardwareOnly = array_diff(BikeType::values(), RidingStyle::values());
        self::assertContains('E-bike', $hardwareOnly);
        self::assertContains('Handbike', $hardwareOnly);
        self::assertContains('Recumbent', $hardwareOnly);
        self::assertContains('Trike', $hardwareOnly);
        self::assertContains('Tandem', $hardwareOnly);
    }
}
