<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use PHPUnit\Framework\TestCase;

/**
 * #30: updatedAt must move for ANY content change (state/name/geom/attributes),
 * not only setAttributes() — otherwise a moderation state flip or a rename
 * leaves the timestamp stale.
 */
final class ItemTimestampTest extends TestCase
{
    private static function ageItem(Item $item): \DateTimeImmutable
    {
        // Force an old updatedAt via reflection (no setter — it's derived).
        $old = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');
        $ref = new \ReflectionProperty(Item::class, 'updatedAt');
        $ref->setValue($item, $old);

        return $old;
    }

    public function testSetStateBumpsUpdatedAt(): void
    {
        $item = new Item();
        $old = self::ageItem($item);
        $item->setState(ItemState::Verified);
        self::assertGreaterThan($old, $item->getUpdatedAt());
    }

    public function testSetNameBumpsUpdatedAt(): void
    {
        $item = new Item();
        $old = self::ageItem($item);
        $item->setName('Renamed');
        self::assertGreaterThan($old, $item->getUpdatedAt());
    }

    public function testSetGeomBumpsUpdatedAt(): void
    {
        $item = new Item();
        $old = self::ageItem($item);
        $item->setGeom('{"type":"Point","coordinates":[5,50]}');
        self::assertGreaterThan($old, $item->getUpdatedAt());
    }

    public function testSetAttributesStillBumpsUpdatedAt(): void
    {
        $item = new Item();
        $old = self::ageItem($item);
        $item->setAttributes(['x' => 1]);
        self::assertGreaterThan($old, $item->getUpdatedAt());
    }
}
