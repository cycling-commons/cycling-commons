<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\ItemType;
use App\Catalog\ServiceKind;
use PHPUnit\Framework\TestCase;

/**
 * The D (BikeServices) improve form is kind-aware: `openingHours` only makes
 * sense for a staffed shop (spec §5) — a self-service station or a public
 * pump is 24/7 by nature, so asking for its "hours" is meaningless.
 */
final class CatalogFormRegistryServiceKindTest extends TestCase
{
    private CatalogFormRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        $this->registry = new CatalogFormRegistry();
    }

    public function testStationHasNoOpeningHoursField(): void
    {
        $set = $this->registry->for(ItemType::BikeServices, ServiceKind::Station);

        self::assertFalse($this->hasField($set->fields, 'openingHours'), 'a station must not carry an openingHours field');
    }

    public function testPumpHasNoOpeningHoursField(): void
    {
        $set = $this->registry->for(ItemType::BikeServices, ServiceKind::Pump);

        self::assertFalse($this->hasField($set->fields, 'openingHours'), 'a pump must not carry an openingHours field');
    }

    public function testShopHasOpeningHoursField(): void
    {
        $set = $this->registry->for(ItemType::BikeServices, ServiceKind::Shop);

        self::assertTrue($this->hasField($set->fields, 'openingHours'), 'a shop must carry an openingHours field');
    }

    public function testDefaultNullPreservesTodaysBehaviourAndKeepsOpeningHours(): void
    {
        $set = $this->registry->for(ItemType::BikeServices);

        self::assertTrue($this->hasField($set->fields, 'openingHours'), 'null (unknown kind) must preserve today\'s behaviour: openingHours present');

        // Explicit null must behave identically to the omitted default.
        $setExplicitNull = $this->registry->for(ItemType::BikeServices, null);
        self::assertTrue($this->hasField($setExplicitNull->fields, 'openingHours'));
    }

    /** @param list<CatalogField> $fields */
    private function hasField(array $fields, string $name): bool
    {
        foreach ($fields as $field) {
            if ($name === $field->name) {
                return true;
            }
        }

        return false;
    }
}
