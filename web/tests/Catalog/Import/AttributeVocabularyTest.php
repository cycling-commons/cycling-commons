<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog\Import;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\ItemType;
use PHPUnit\Framework\TestCase;

/**
 * C2-T5: the new difficulty/suitability attributes (effort/famousFor/approach
 * for climbs, accessibility for stays) must be registry-declared and therefore
 * vocabulary-valid, without breaking any existing allowed key.
 */
final class AttributeVocabularyTest extends TestCase
{
    private AttributeVocabulary $vocabulary;

    #[\Override]
    protected function setUp(): void
    {
        $this->vocabulary = new AttributeVocabulary(new CatalogFormRegistry());
    }

    public function testClimbsAcceptsEffortFamousForAndApproach(): void
    {
        $this->vocabulary->assertValid(ItemType::Climbs, [
            'effort' => 'Tough',
            'famousFor' => 'La Flèche Wallonne summit finish',
            'approach' => 'From Sougné-Remouchamps (Aywaille)',
        ]);
        $this->addToAssertionCount(1); // no exception == pass
    }

    public function testClimbsStillAcceptsExistingRoadQualityAndTrafficKeys(): void
    {
        // sq/tr are pre-existing EXTRAS keys (road quality / traffic) — must not
        // be duplicated or broken by the new registry fields.
        $this->vocabulary->assertValid(ItemType::Climbs, ['sq' => 'Good', 'tr' => 'Quiet']);
        $this->addToAssertionCount(1);
    }

    public function testWhereToSleepAcceptsAccessibility(): void
    {
        $this->vocabulary->assertValid(ItemType::WhereToSleep, ['accessibility' => 'Step-free access']);
        $this->addToAssertionCount(1);
    }

    public function testUnknownKeyStillThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vocabulary->assertValid(ItemType::Climbs, ['notARealKey' => 'nope']);
    }

    public function testEffortFamousForApproachAreRegistryDeclaredNotExtras(): void
    {
        // The new keys must come from the registry fields (auto-contributed),
        // not from a bespoke EXTRAS entry — confirms the "don't duplicate the
        // mechanism" constraint from the task.
        $registry = new CatalogFormRegistry();
        $names = array_map(
            static fn ($f): string => $f->name,
            $registry->for(ItemType::Climbs)->all(),
        );

        self::assertContains('effort', $names);
        self::assertContains('famousFor', $names);
        self::assertContains('approach', $names);
    }

    public function testAccessibilityIsRegistryDeclaredNotExtras(): void
    {
        $registry = new CatalogFormRegistry();
        $names = array_map(
            static fn ($f): string => $f->name,
            $registry->for(ItemType::WhereToSleep)->all(),
        );

        self::assertContains('accessibility', $names);
    }
}
