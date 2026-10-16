<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\V1\PublicFields;
use App\Catalog\ItemType;
use PHPUnit\Framework\TestCase;

/**
 * One rule for an item's kind in the read API (public-api.md section 2.2):
 * the stored kind, else the one its type label names, else null.
 */
final class PublicFieldsKindTest extends TestCase
{
    public function testTheStoredKindWins(): void
    {
        self::assertSame('pump', PublicFields::kindOf(ItemType::BikeServices, ['serviceKind' => 'pump', 't' => 'Bike shop']));
    }

    public function testWithoutAStoredKindTheLabelNamesIt(): void
    {
        self::assertSame('station', PublicFields::kindOf(ItemType::BikeServices, ['t' => 'Public repair station']));
        self::assertSame('station', PublicFields::kindOf(ItemType::BikeServices, ['serviceKind' => '', 't' => 'E-bike charging station']));
        self::assertNull(PublicFields::kindOf(ItemType::BikeServices, ['t' => 'Something else']));
        self::assertNull(PublicFields::kindOf(ItemType::BikeServices, []));
    }

    public function testALetterWithoutKindsHasNone(): void
    {
        self::assertNull(PublicFields::kindOf(ItemType::WaterFood, ['type' => 'Public fountain', 't' => 'Bike shop']));
    }
}
