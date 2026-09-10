<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\FieldKind;
use App\Catalog\ItemType;
use PHPUnit\Framework\TestCase;

/**
 * The per-type field schemas (the "Fix details" + "Add missing" panes) ported
 * from atlas/demo/edit-items.js. Demo fixture shape — not a domain schema.
 */
final class CatalogFormRegistryTest extends TestCase
{
    private CatalogFormRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        $this->registry = new CatalogFormRegistry();
    }

    public function testEveryTypeHasAtLeastOneFixField(): void
    {
        foreach (ItemType::cases() as $type) {
            self::assertNotEmpty(
                $this->registry->for($type)->fields,
                "Type {$type->value} must have Fix-detail fields",
            );
        }
    }

    public function testFieldNamesAreUniqueWithinAType(): void
    {
        // fields + addFields all become Symfony form children — a duplicate key
        // would silently clobber a field, so names must be globally unique per type.
        foreach (ItemType::cases() as $type) {
            $set = $this->registry->for($type);
            $names = array_map(
                static fn (CatalogField $f): string => $f->name,
                [...$set->fields, ...$set->addFields],
            );

            self::assertSame(
                array_values(array_unique($names)),
                $names,
                "Type {$type->value} has duplicate field names",
            );
        }
    }

    public function testSelectFieldsHaveChoicesAndOthersDoNot(): void
    {
        foreach (ItemType::cases() as $type) {
            $set = $this->registry->for($type);
            foreach ([...$set->fields, ...$set->addFields] as $field) {
                if (\in_array($field->kind, [FieldKind::Select, FieldKind::MultiSelect], true)) {
                    self::assertNotEmpty($field->choices, "{$type->value}.{$field->name} select needs choices");
                } else {
                    self::assertSame([], $field->choices, "{$type->value}.{$field->name} non-select must not carry choices");
                }
            }
        }
    }

    public function testRoadSurfaceFieldsMatchSpec(): void
    {
        $set = $this->registry->for(ItemType::RoadSurface);
        $byName = [];
        foreach ([...$set->fields, ...$set->addFields] as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('surface', $byName);
        self::assertSame(FieldKind::Select, $byName['surface']->kind);
        self::assertContains('Asphalt', $byName['surface']->choices);
        self::assertContains('Gravel', $byName['surface']->choices);
        self::assertContains('Dirt', $byName['surface']->choices);
        self::assertContains('Rock', $byName['surface']->choices);
        self::assertNotContains('Ground', $byName['surface']->choices, 'Ground was renamed to Dirt — the label now matches the harvester (MTB terrain carry-in)');

        self::assertArrayHasKey('note', $byName);
        self::assertSame(FieldKind::Textarea, $byName['note']->kind);

        // Add-missing pane is type-specific too.
        self::assertArrayHasKey('seasonalClosure', $byName);
    }

    public function testWaterFoodKeepsThePotableJudgementOption(): void
    {
        $set = $this->registry->for(ItemType::WaterFood);
        $potable = null;
        foreach ($set->fields as $f) {
            if ('potable' === $f->name) {
                $potable = $f;
            }
        }

        self::assertNotNull($potable);
        self::assertContains('Unknown', $potable->choices);
        // Never a spelling starting with "No": ModerationService and icons.js
        // both prefix-match that as non-potable (owner 2026-09-10).
        foreach ($potable->choices as $choice) {
            self::assertSame(str_starts_with($choice, 'No'), 'No / non-potable' === $choice);
        }
    }

    public function testClimbsCarriesEffortFamousForAndApproach(): void
    {
        // C2-T5: real, filterable difficulty/suitability attributes, framed as
        // "what the climb is like" — replaces the hardcoded drawer decoration.
        $set = $this->registry->for(ItemType::Climbs);
        $byName = [];
        foreach ($set->all() as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('effort', $byName);
        self::assertSame(FieldKind::Select, $byName['effort']->kind);
        self::assertSame(['Steady', 'Challenging', 'Tough', 'Very steep'], $byName['effort']->choices);

        self::assertArrayHasKey('shade', $byName);
        self::assertSame(['Unknown', 'Wooded', 'Partly shaded', 'Exposed'], $byName['shade']->choices, 'Partly shaded sits between Wooded and Exposed (owner request 2026-08-25)');

        self::assertArrayHasKey('famousFor', $byName);
        self::assertSame(FieldKind::Text, $byName['famousFor']->kind);

        self::assertArrayHasKey('approach', $byName);
        self::assertSame(FieldKind::Text, $byName['approach']->kind);
    }

    public function testWhereToSleepCarriesAccessibility(): void
    {
        // C2-T5: "disability-friendly stay" rendered as a real, filterable attribute.
        $set = $this->registry->for(ItemType::WhereToSleep);
        $byName = [];
        foreach ($set->all() as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('accessibility', $byName);
        // MULTI, not single (2026-08-02): a stay is routinely step-free AND
        // handbike-friendly, and one-of-these forced the rider to drop the
        // rest — the very fact a rider who needs one of them searches for.
        // 'Unknown' went with the single select: nothing ticked already means
        // "not stated", and it cannot coexist with a real value.
        self::assertSame(FieldKind::MultiSelect, $byName['accessibility']->kind);
        self::assertSame(
            ['Step-free access', 'Handbike-friendly', 'Wheelchair-accessible'],
            $byName['accessibility']->choices,
        );
    }

    public function testWhereToSleepWebAndBookingLinkAreUrlFields(): void
    {
        // Security review 2026-07-07 (critical #3): both user-editable link
        // fields must be url-kind so CatalogFieldConstraints restricts them to
        // http/https — a plain-text `javascript:` value used to reach the map's
        // <a href> (stored XSS).
        $set = $this->registry->for(ItemType::WhereToSleep);
        $byName = [];
        foreach ($set->all() as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('web', $byName);
        self::assertSame(FieldKind::Url, $byName['web']->kind, 'web must be a URL field');
        self::assertArrayHasKey('bookingLink', $byName);
        self::assertSame(FieldKind::Url, $byName['bookingLink']->kind, 'bookingLink must be a URL field');
    }

    public function testQualityRidesCarriesExperienceRatings(): void
    {
        $set = $this->registry->for(ItemType::QualityRides);
        $names = array_map(
            static fn (CatalogField $f): string => $f->name,
            [...$set->fields, ...$set->addFields],
        );

        self::assertContains('rideName', $names);
        self::assertContains('difficulty', $names);
        // The six cyclist-experience attributes live in the Add-missing pane.
        self::assertContains('quietness', $names);
        self::assertContains('bestDirection', $names);
    }

    /** P2-D2: bikeTypes is a single multi-select over BikeType::values() —
     *  the old single-select field plus a separate 'handbike' field are gone;
     *  Handbike is now just one of the bikeTypes choices. */
    public function testQualityRidesBikeTypesIsMultiSelectAndHandbikeFolded(): void
    {
        $set = $this->registry->for(ItemType::QualityRides);
        $byName = [];
        foreach ([...$set->fields, ...$set->addFields] as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('bikeTypes', $byName);
        self::assertSame(FieldKind::MultiSelect, $byName['bikeTypes']->kind);
        self::assertSame(\App\Catalog\BikeType::values(), $byName['bikeTypes']->choices);
        self::assertArrayNotHasKey('handbike', $byName, 'handbike is folded into bikeTypes, not a separate field');
    }

    public function testIntakeFieldsAreMarkedNonDisplay(): void
    {
        // The item title ('name'/'rideName') and free-text 'correction' intake
        // are form inputs, not display facts — they must never render as drawer rows.
        $intake = ['name', 'correction', 'rideName'];
        foreach (ItemType::cases() as $type) {
            foreach ($this->registry->for($type)->all() as $field) {
                if (\in_array($field->name, $intake, true)) {
                    self::assertFalse($field->display, "{$type->value}.{$field->name} must be display:false");
                } else {
                    self::assertTrue($field->display, "{$type->value}.{$field->name} must be display:true");
                }
            }
        }
    }

    public function testNoChoiceNamesOneRegionsLocalBrand(): void
    {
        // RAVeL is Wallonia's greenway network. It sat in the A-layer traffic
        // dropdown that now describes twelve countries, where it was both
        // parochial and simply wrong (owner review 2026-08-12). The general
        // rule it stands for: a choice a rider picks from is vocabulary, and
        // vocabulary may not assume which country they are in.
        $local = ['RAVeL', 'Knooppunt', 'Bundesstraße', 'Sustrans'];
        foreach (ItemType::cases() as $type) {
            foreach ($this->registry->for($type)->all() as $field) {
                foreach ($field->choices as $choice) {
                    foreach ($local as $brand) {
                        self::assertStringNotContainsString(
                            $brand,
                            $choice,
                            "{$type->value}.{$field->name} offers '{$choice}', which names one region's network",
                        );
                    }
                }
            }
        }
    }

    public function testTheSurfaceTrafficAndSegregationQuestionsStayTogether(): void
    {
        // They are one question asked twice — how much motor traffic, and is it
        // kept off? Split across the two panes they landed pages apart on a
        // narrow screen (owner-reported 2026-08-12).
        $names = array_map(
            static fn ($f) => $f->name,
            $this->registry->for(ItemType::RoadSurface)->fields,
        );
        $traffic = array_search('traffic', $names, true);
        $segregated = array_search('segregated', $names, true);
        self::assertIsInt($traffic, 'traffic is a "Fix details" field');
        self::assertIsInt($segregated, 'segregated moved out of "Add missing" to sit beside it');
        self::assertSame(1, $segregated - $traffic, 'they must remain adjacent');
    }
}
