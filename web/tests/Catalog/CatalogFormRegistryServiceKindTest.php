<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\ItemFieldSet;
use App\Catalog\ItemType;
use App\Catalog\ServiceKind;
use PHPUnit\Framework\TestCase;

/**
 * The D (BikeServices) improve form is kind-aware in its opening-hours DEFAULT
 * (spec §5): a staffed shop defaults to 'Unknown', while a self-service station
 * or public pump is unmanned and defaults to the assumed '24/7' — preselected
 * but overridable, because some stations follow a host building's hours (e.g.
 * a repair station inside a library).
 */
final class CatalogFormRegistryServiceKindTest extends TestCase
{
    private CatalogFormRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        $this->registry = new CatalogFormRegistry();
    }

    public function testStationDefaultsOpeningHoursTo247(): void
    {
        $field = $this->field($this->registry->for(ItemType::BikeServices, ServiceKind::Station), 'openingHours');

        self::assertNotNull($field, 'a station must carry the openingHours field');
        self::assertSame('24/7', $field->default, 'unmanned station: 24/7 preselected');
    }

    public function testPumpDefaultsOpeningHoursTo247(): void
    {
        $field = $this->field($this->registry->for(ItemType::BikeServices, ServiceKind::Pump), 'openingHours');

        self::assertNotNull($field, 'a pump must carry the openingHours field');
        self::assertSame('24/7', $field->default, 'unmanned pump: 24/7 preselected');
    }

    public function testShopDefaultsOpeningHoursToUnknown(): void
    {
        $field = $this->field($this->registry->for(ItemType::BikeServices, ServiceKind::Shop), 'openingHours');

        self::assertNotNull($field, 'a shop must carry the openingHours field');
        self::assertSame('Unknown', $field->default, 'staffed shop: hours unverified until someone tells us');
    }

    public function testDefaultNullPreservesTodaysBehaviour(): void
    {
        $field = $this->field($this->registry->for(ItemType::BikeServices), 'openingHours');

        self::assertNotNull($field, 'null (unknown kind) must preserve today\'s behaviour: openingHours present');
        self::assertSame('Unknown', $field->default);

        // Explicit null must behave identically to the omitted default.
        $explicitNull = $this->field($this->registry->for(ItemType::BikeServices, null), 'openingHours');
        self::assertNotNull($explicitNull);
        self::assertSame('Unknown', $explicitNull->default);
    }

    private function field(ItemFieldSet $set, string $name): ?CatalogField
    {
        foreach ($set->fields as $field) {
            if ($name === $field->name) {
                return $field;
            }
        }

        return null;
    }
}
