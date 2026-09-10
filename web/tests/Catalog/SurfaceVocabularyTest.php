<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\SurfaceVocabulary;
use PHPUnit\Framework\TestCase;

final class SurfaceVocabularyTest extends TestCase
{
    public function testSuggestsTheDominantCoarseBucketFromAProfile(): void
    {
        $profile = ['covered' => 80, 'parts' => [
            ['surface' => 'Asphalt', 'pct' => 30],
            ['surface' => 'Fine gravel', 'pct' => 45],
            ['surface' => 'Gravel', 'pct' => 25],
        ]];
        // 30 Asphalt vs 70 Gravel → Gravel.
        self::assertSame('Gravel', SurfaceVocabulary::suggestFromProfile($profile));
    }

    public function testNullProfileYieldsNoSuggestion(): void
    {
        self::assertNull(SurfaceVocabulary::suggestFromProfile(null));
    }

    public function testDirtAndRockBucketIntoTheCoarseGravelCategory(): void
    {
        $profile = ['covered' => 60, 'parts' => [
            ['surface' => 'Asphalt', 'pct' => 20],
            ['surface' => 'Rock', 'pct' => 50],
            ['surface' => 'Dirt', 'pct' => 30],
        ]];
        // 20 Asphalt vs 80 Gravel (Rock+Dirt combined) → Gravel.
        self::assertSame('Gravel', SurfaceVocabulary::suggestFromProfile($profile));
    }

    public function testCyclewayRavelBucketsToAsphaltNotMixed(): void
    {
        // 'Cycleway · RAVeL' is the harvester's largest served surface label (route_surfaces.py's
        // SURF['cycleway']) — smooth car-free asphalt. Before BUCKETS covered it, this fell through
        // to the '?? Mixed' default; it must bucket to Asphalt.
        $profile = ['covered' => 90, 'parts' => [
            ['surface' => 'Cycleway · RAVeL', 'pct' => 70],
            ['surface' => 'Gravel', 'pct' => 30],
        ]];
        self::assertSame('Asphalt', SurfaceVocabulary::suggestFromProfile($profile));
    }
}
