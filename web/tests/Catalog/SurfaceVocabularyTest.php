<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
}
