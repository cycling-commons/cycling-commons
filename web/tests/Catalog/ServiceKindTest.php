<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ServiceKind;
use PHPUnit\Framework\TestCase;

final class ServiceKindTest extends TestCase
{
    public function testFromOsmTags(): void
    {
        self::assertSame(ServiceKind::Shop, ServiceKind::fromOsmTags(['shop' => 'bicycle']));
        self::assertSame(ServiceKind::Station, ServiceKind::fromOsmTags(['amenity' => 'bicycle_repair_station']));
        self::assertSame(ServiceKind::Pump, ServiceKind::fromOsmTags(['amenity' => 'compressed_air']));
        self::assertNull(ServiceKind::fromOsmTags(['shop' => 'bakery']));
    }

    public function testFromLegacyLabel(): void
    {
        self::assertSame(ServiceKind::Shop, ServiceKind::fromLegacyLabel('Bike shop'));
        self::assertSame(ServiceKind::Station, ServiceKind::fromLegacyLabel('Repair station'));
        self::assertSame(ServiceKind::Station, ServiceKind::fromLegacyLabel('Public repair station'));
        self::assertSame(ServiceKind::Station, ServiceKind::fromLegacyLabel('E-bike charging station'));
        self::assertSame(ServiceKind::Pump, ServiceKind::fromLegacyLabel('Pump'));
        self::assertNull(ServiceKind::fromLegacyLabel(null));
    }

    public function testHasOpeningHours(): void
    {
        self::assertTrue(ServiceKind::Shop->hasOpeningHours());
        self::assertFalse(ServiceKind::Station->hasOpeningHours());
        self::assertFalse(ServiceKind::Pump->hasOpeningHours());
    }
}
