<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\PlaceKind;
use App\Catalog\StayKind;
use PHPUnit\Framework\TestCase;

/** Every stay type label in the catalogue on 2026-10-03 maps to one of the five drawings. */
final class StayKindTest extends TestCase
{
    public function testEveryKnownLabelHasADrawing(): void
    {
        $labels = ['Hotel' => 'hotel', 'Guest house' => 'house', 'B&B' => 'house', 'Gîte' => 'house', 'Gîte / guesthouse' => 'house',
            'Furnished rental' => 'house', 'Campsite' => 'camp', 'Hostel' => 'hostel', 'Budget stay' => 'hostel',
            'Chalet' => 'chalet', 'Mountain hut' => 'chalet'];
        foreach ($labels as $label => $kind) {
            self::assertSame($kind, StayKind::fromLabel($label), $label);
            self::assertArrayHasKey($kind, StayKind::PATHS);
        }
    }

    /** The stay Type a rider can change (PlaceKind, letter O) picks the drawing first. */
    public function testEveryStayTypeHasADrawing(): void
    {
        foreach (array_keys(PlaceKind::labels('O')) as $type) {
            $kind = StayKind::fromType($type);
            self::assertNotNull($kind, $type);
            self::assertArrayHasKey($kind, StayKind::PATHS);
        }
        self::assertSame('camp', StayKind::fromType('camp'));
        self::assertSame('house', StayKind::fromType('guest_house'));
        self::assertNull(StayKind::fromType(null));
    }

    public function testAnUnknownLabelHasNone(): void
    {
        self::assertNull(StayKind::fromLabel('Castle'));
        self::assertNull(StayKind::fromLabel(null));
        self::assertSame('hotel', StayKind::fromLabel('  HOTEL '));
    }
}
