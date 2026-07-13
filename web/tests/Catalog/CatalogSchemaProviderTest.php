<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\CatalogSchemaProvider;
use App\Catalog\ItemType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class CatalogSchemaProviderTest extends TestCase
{
    private CatalogSchemaProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        // IdentityTranslator returns the id (label) unchanged — i.e. the EN source string.
        $this->provider = new CatalogSchemaProvider(new CatalogFormRegistry(), new IdentityTranslator());
    }

    public function testDisplayFieldsExcludeIntakeAndCarryLabelAndKind(): void
    {
        $fields = $this->provider->displayFields(ItemType::BikeServices);
        $keys = array_column($fields, 'key');

        self::assertNotContains('name', $keys);
        self::assertNotContains('correction', $keys);

        $byKey = array_column($fields, null, 'key');
        self::assertSame(['key' => 'tools', 'label' => 'Tools available', 'kind' => 'text'], $byKey['tools']);
    }

    public function testAllIsKeyedByLetterAndSurfacesTheDriftedClimbFields(): void
    {
        $all = $this->provider->all();
        self::assertArrayHasKey('D', $all);
        self::assertArrayHasKey('B', $all);

        // The fields the old map.js whitelist dropped must now be present.
        $climbKeys = array_column($all['B'], 'key');
        self::assertContains('waterOnClimb', $climbKeys);
        self::assertContains('hairpins', $climbKeys);
        self::assertContains('shade', $climbKeys);
    }

    public function testRatingSelectsSerialiseWithRatingKind(): void
    {
        $byKey = array_column($this->provider->displayFields(ItemType::QualityRides), null, 'key');
        self::assertSame('rating', $byKey['quietness']['kind']);
        self::assertSame('rating', $byKey['scenic']['kind']);
        self::assertSame('rating', $byKey['friendliness']['kind']);
        self::assertSame('select', $byKey['dominantSurface']['kind']);   // a non-1-5 select is untouched
        self::assertSame('multiselect', $byKey['bikeTypes']['kind']);    // multiselect untouched
    }
}
