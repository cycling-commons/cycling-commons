<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Contribution\BikeWayReading;
use PHPUnit\Framework\TestCase;

/**
 * The form's warning and the coverage load keep scenic views to the same range.
 */
final class BikeWayReadingTest extends TestCase
{
    public function testTheRangeIsTheContractsScenicNearWay(): void
    {
        $path = \dirname(__DIR__, 3).'/pipeline/contract/coverage-contract.json';
        if (!is_file($path)) {
            self::markTestSkipped('pipeline/ is not mounted beside web/ here');
        }
        $contract = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(BikeWayReading::SCENIC_WITHIN_M, $contract['letters']['P']['nearWay']['withinM']);
    }

    public function testFarIsKnownAndOutOfRange(): void
    {
        self::assertFalse((new BikeWayReading(true, 249.0))->far());
        self::assertTrue((new BikeWayReading(true, 251.0))->far());
        self::assertTrue((new BikeWayReading(true, null))->far());
        self::assertFalse(BikeWayReading::unknown()->far());
    }
}
