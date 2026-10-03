<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

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

    public function testAnUnknownLabelHasNone(): void
    {
        self::assertNull(StayKind::fromLabel('Castle'));
        self::assertNull(StayKind::fromLabel(null));
        self::assertSame('hotel', StayKind::fromLabel('  HOTEL '));
    }
}
