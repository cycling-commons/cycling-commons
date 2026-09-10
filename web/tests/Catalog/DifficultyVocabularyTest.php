<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\DifficultyVocabulary;
use PHPUnit\Framework\TestCase;

final class DifficultyVocabularyTest extends TestCase
{
    public function testCanonicalLabelMapsToScoreLabel(): void
    {
        self::assertSame(['score' => 3, 'label' => 'Challenging'], DifficultyVocabulary::canonical('Challenging'));
    }

    public function testEveryCanonicalLabelRoundTrips(): void
    {
        self::assertSame(['score' => 1, 'label' => 'Easy'], DifficultyVocabulary::canonical('Easy'));
        self::assertSame(['score' => 5, 'label' => 'Very hard'], DifficultyVocabulary::canonical('Very hard'));
    }

    public function testImportShapeIsValidatedAndClamped(): void
    {
        self::assertSame(['score' => 4, 'label' => 'Hard'], DifficultyVocabulary::canonical(['score' => 4, 'label' => 'Hard']));
    }

    public function testUnknownReturnsNull(): void
    {
        self::assertNull(DifficultyVocabulary::canonical('Spicy'));
        // Retired legacy rider vocab (normalized away by Version20260708140000).
        self::assertNull(DifficultyVocabulary::canonical('Gentle'));
        self::assertNull(DifficultyVocabulary::canonical(null));
    }
}
