<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Moderation\SampleQueue;
use PHPUnit\Framework\TestCase;

final class SampleQueueFilterTest extends TestCase
{
    public function testEmptyFiltersReturnEveryItem(): void
    {
        self::assertSame(SampleQueue::items(), SampleQueue::filtered(null, null, null));
    }

    public function testItemsCarryCountryAndRegion(): void
    {
        foreach (SampleQueue::items() as $item) {
            self::assertArrayHasKey('country', $item);
            self::assertArrayHasKey('region', $item);
            self::assertNotSame('', $item['country']);
        }
    }

    public function testFilterByCountryReturnsOnlyThatCountry(): void
    {
        $nl = SampleQueue::filtered('NL', null, null);
        self::assertNotEmpty($nl);
        foreach ($nl as $item) {
            self::assertSame('NL', $item['country']);
        }
        self::assertLessThan(count(SampleQueue::items()), count($nl));
    }

    public function testFilterByRegionReturnsOnlyThatRegion(): void
    {
        $limburg = SampleQueue::filtered(null, 'Limburg', null);
        self::assertNotEmpty($limburg);
        foreach ($limburg as $item) {
            self::assertSame('Limburg', $item['region']);
        }
        self::assertLessThan(count(SampleQueue::items()), count($limburg));
    }

    public function testFilterByTypeReturnsOnlyThatType(): void
    {
        $hazards = SampleQueue::filtered(null, null, 'hazard');
        self::assertNotEmpty($hazards);
        foreach ($hazards as $item) {
            self::assertSame('hazard', $item['type']);
        }
    }

    public function testCountriesAndRegionsAreSortedDistinct(): void
    {
        $countries = SampleQueue::countries();
        self::assertContains('BE', $countries);
        self::assertContains('NL', $countries);
        self::assertSame(array_values(array_unique($countries)), $countries);
        $sorted = $countries;
        sort($sorted);
        self::assertSame($sorted, $countries);

        $regions = SampleQueue::regions();
        self::assertSame(array_values(array_unique($regions)), $regions);
        // Locale-aware ordering regression: a byte-wise sort puts "Liège" after
        // "Limburg" (è = 0xC3 > m = 0x6D); the Collator must not.
        $liege = array_search('Liège', $regions, true);
        $limburg = array_search('Limburg', $regions, true);
        self::assertNotFalse($liege);
        self::assertNotFalse($limburg);
        self::assertLessThan($limburg, $liege);
    }
}
