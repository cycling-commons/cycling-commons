<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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

    public function testLegacyRiderStringMapsToCanonical(): void
    {
        self::assertSame(['score' => 1, 'label' => 'Easy'], DifficultyVocabulary::canonical('Gentle'));
        self::assertSame(['score' => 5, 'label' => 'Very hard'], DifficultyVocabulary::canonical('Very hard'));
    }

    public function testImportShapeIsValidatedAndClamped(): void
    {
        self::assertSame(['score' => 4, 'label' => 'Hard'], DifficultyVocabulary::canonical(['score' => 4, 'label' => 'Hard']));
    }

    public function testUnknownReturnsNull(): void
    {
        self::assertNull(DifficultyVocabulary::canonical('Spicy'));
        self::assertNull(DifficultyVocabulary::canonical(null));
    }
}
