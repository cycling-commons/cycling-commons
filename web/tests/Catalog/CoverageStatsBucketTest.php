<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CoverageStatsProvider;
use App\Catalog\ItemSource;
use PHPUnit\Framework\TestCase;

/** Every item source has its /coverage bucket: a Scout tag is a rider's, never "derived". */
final class CoverageStatsBucketTest extends TestCase
{
    public function testEverySourceHasItsBucket(): void
    {
        self::assertSame('riders', CoverageStatsProvider::bucketFor(ItemSource::Scout));
        self::assertSame('riders', CoverageStatsProvider::bucketFor(ItemSource::User));
        self::assertSame('osm', CoverageStatsProvider::bucketFor(ItemSource::Osm));
        self::assertSame('derived', CoverageStatsProvider::bucketFor(ItemSource::Auto));
        foreach (ItemSource::cases() as $source) {
            self::assertContains(CoverageStatsProvider::bucketFor($source), ['osm', 'partner', 'riders', 'derived']);
        }
    }
}
