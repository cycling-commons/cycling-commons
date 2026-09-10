<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use PHPUnit\Framework\TestCase;

/**
 * `osm_ref IS NULL` cannot mean both "no OSM counterpart" and "nobody looked",
 * and before this it meant the second for all 1,525 rows
 * (catalog-data-model.md §5b).
 */
final class OsmIdentityTest extends TestCase
{
    public function testAFreshRowHasNotBeenAsked(): void
    {
        $item = new Item();
        self::assertNull($item->getOsmRef());
        self::assertNull($item->getOsmCheckedAt());
        self::assertFalse($item->osmAnswered(), 'nobody has looked yet');
    }

    public function testLinkingAnswersTheQuestion(): void
    {
        $item = (new Item())->answerOsm('node/930340800');
        self::assertSame('node/930340800', $item->getOsmRef());
        self::assertTrue($item->osmAnswered());
    }

    public function testNoCounterpartIsAnAnswerAndNotAGap(): void
    {
        $item = (new Item())->answerOsm(null);
        self::assertNull($item->getOsmRef(), 'there is no object to name');
        self::assertTrue($item->osmAnswered(), 'but the question was answered');
    }

    public function testAnsweringTwiceRestampsRatherThanRefusing(): void
    {
        $item = (new Item())->answerOsm(null);
        $first = $item->getOsmCheckedAt();
        self::assertNotNull($first);
        $item->answerOsm('way/12345');
        self::assertSame('way/12345', $item->getOsmRef());
        self::assertNotNull($item->getOsmCheckedAt());
        self::assertGreaterThanOrEqual($first, $item->getOsmCheckedAt());
    }

    public function testAnEmptyStringRefIsStoredAsNoRefButStillAnswered(): void
    {
        // The form layer posts '' when the curator picks "no counterpart";
        // storing '' would defeat every `osm_ref IS NULL` predicate.
        $item = (new Item())->answerOsm('');
        self::assertNull($item->getOsmRef());
        self::assertTrue($item->osmAnswered());
    }
}
