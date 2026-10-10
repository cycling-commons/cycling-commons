<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportRetention;
use PHPUnit\Framework\TestCase;

/**
 * Which snapshots stay (docs/specs/api-strategy.md §3.1): the newest few
 * weeks, and the first snapshot of every month for good.
 */
final class BulkExportRetentionTest extends TestCase
{
    public function testTheRecentWeeksAndTheFirstOfEachMonthAreKept(): void
    {
        $published = [
            '20260803T050700Z', '20260810T050700Z', '20260817T050700Z',
            '20260907T050700Z', '20260914T050700Z', '20260921T050700Z', '20260928T050700Z',
            '20261005T050700Z', '20261012T050700Z',
        ];

        self::assertSame([
            '20260803T050700Z',                     // first of August, kept for good
            '20260907T050700Z',                     // first of September, kept for good
            '20260921T050700Z', '20260928T050700Z', // the newest four weeks
            '20261005T050700Z', '20261012T050700Z', // (October's first among them)
        ], BulkExportRetention::kept($published));
    }

    public function testOnlyTheFirstOfAMonthIsArchived(): void
    {
        $published = ['20260803T050700Z', '20260810T050700Z', '20260907T050700Z'];

        self::assertTrue(BulkExportRetention::isMonthlyFirst('20260803T050700Z', $published));
        self::assertFalse(BulkExportRetention::isMonthlyFirst('20260810T050700Z', $published));
        self::assertTrue(BulkExportRetention::isMonthlyFirst('20260907T050700Z', $published));
    }

    public function testFewSnapshotsAreAllKept(): void
    {
        self::assertSame(['20261009T231033Z'], BulkExportRetention::kept(['20261009T231033Z']));
        self::assertSame([], BulkExportRetention::kept([]));
    }
}
