<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeTypeVocabulary;
use PHPUnit\Framework\TestCase;

final class BikeTypeVocabularyTest extends TestCase
{
    public function testListIsFilteredToValidTypes(): void
    {
        self::assertSame(['Road', 'Gravel'], BikeTypeVocabulary::normalize(['Road', 'Gravel', 'Unicycle']));
    }

    public function testLegacyStringBecomesSingletonList(): void
    {
        self::assertSame(['MTB'], BikeTypeVocabulary::normalize('MTB'));
    }

    public function testAnyExpandsToGeneralBikes(): void
    {
        self::assertSame(['Road', 'Gravel', 'MTB', 'E-bike'], BikeTypeVocabulary::normalize('Any'));
    }

    public function testLegacyHandbikeYesFoldsIn(): void
    {
        self::assertSame(['Gravel', 'Handbike'], BikeTypeVocabulary::normalize(['Gravel'], 'Yes'));
    }
}
