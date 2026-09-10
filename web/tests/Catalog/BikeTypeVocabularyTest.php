<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BikeTypeVocabulary;
use PHPUnit\Framework\TestCase;

final class BikeTypeVocabularyTest extends TestCase
{
    public function testFiltersToValidTypesAndDeduplicates(): void
    {
        self::assertSame(
            ['Road', 'Gravel'],
            BikeTypeVocabulary::normalize(['Road', 'Gravel', 'Road', 'Unicycle']),
        );
    }

    public function testHandbikeAndOtherHardwareAreOrdinaryValues(): void
    {
        self::assertSame(
            ['Gravel', 'Handbike', 'Trike', 'Tandem'],
            BikeTypeVocabulary::normalize(['Gravel', 'Handbike', 'Trike', 'Tandem']),
        );
    }

    public function testNonListInputYieldsEmpty(): void
    {
        self::assertSame([], BikeTypeVocabulary::normalize(null));
        self::assertSame([], BikeTypeVocabulary::normalize(''));
        self::assertSame([], BikeTypeVocabulary::normalize('MTB'));
    }
}
