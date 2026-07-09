<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeType;
use PHPUnit\Framework\TestCase;

/**
 * Recumbent/Trike/Tandem carry-in: genuinely different hardware (turning
 * radius, width, ground clearance) with real route-suitability implications
 * — same reasoning that already made Handbike first-class.
 */
final class BikeTypeTest extends TestCase
{
    public function testValuesIncludesTheThreeNewHardwareTypes(): void
    {
        self::assertSame(
            ['Road', 'Gravel', 'MTB', 'E-bike', 'Handbike', 'Recumbent', 'Trike', 'Tandem'],
            BikeType::values(),
        );
    }
}
