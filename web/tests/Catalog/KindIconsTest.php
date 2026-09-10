<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ItemType;
use App\Catalog\KindIcons;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The kind glyph registry (data-provider-hierarchy.md §6.3): one definition
 * that the map's canvas, the DOM pins and both legends all draw from.
 */
final class KindIconsTest extends TestCase
{
    public function testEveryKindIsDrawableByBothRenderers(): void
    {
        foreach (KindIcons::set() as $letter => $kinds) {
            self::assertNotNull(ItemType::fromLetter($letter), "$letter is a catalogue letter");
            self::assertNotEmpty($kinds);
            foreach ($kinds as $kind => $def) {
                $hasPaths = isset($def['paths']);
                $hasGlyph = isset($def['glyph']);
                self::assertTrue($hasPaths xor $hasGlyph, "$letter/$kind is paths OR a glyph, never both or neither");
                if ($hasPaths) {
                    foreach ($def['paths'] as $path) {
                        // A 24-box path the canvas (Path2D) and Twig (<path d>) both accept.
                        self::assertMatchesRegularExpression('/^M[\d.\- ,a-zA-Z]+[Zz]$/', $path['d'], "$letter/$kind path is a closed 24-box path");
                        self::assertMatchesRegularExpression('/^(#[0-9a-fA-F]{6}|rgba?\(.*\)|@cat|@ink)$/', $path['fill'], "$letter/$kind fill is a colour or a renderer token");
                        if (isset($path['stroke'])) {
                            self::assertArrayHasKey('width', $path, "$letter/$kind stroke names its width");
                        }
                    }
                }
            }
        }
    }

    /**
     * The water blue exists once, in the registry. Nothing else may carry it:
     * the legend rows are generated from here so they cannot drift.
     */
    public function testTheWaterBlueLivesOnlyInTheRegistry(): void
    {
        $root = \dirname(__DIR__, 2);
        foreach (['assets/map/icons.js', 'assets/map/coverage.js', 'templates/map/index.html.twig', 'templates/pages/map_key.html.twig'] as $file) {
            $src = (string) file_get_contents($root.'/'.$file);
            self::assertStringNotContainsStringIgnoringCase(KindIcons::WATER_BLUE, $src, "$file must read the drop from KindIcons, not redraw it");
            self::assertStringNotContainsString('water-drop', $src, "$file still names the pre-registry drop image");
        }
    }

    /**
     * Every kind has its name and its one-line explanation in the default
     * catalogue; the locale-parity gate carries them to the other four.
     */
    public function testEveryKindHasItsLegendStrings(): void
    {
        $messages = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.en.yaml');
        self::assertIsArray($messages);
        $legend = $messages['legend'] ?? [];
        self::assertIsArray($legend);
        foreach (KindIcons::set() as $letter => $kinds) {
            foreach (array_keys($kinds) as $kind) {
                $stem = 'kind_'.strtolower($letter).'_'.$kind;   // the key both legends compose
                self::assertArrayHasKey($stem.'_h', $legend, "$letter/$kind has a name");
                self::assertArrayHasKey($stem.'_t', $legend, "$letter/$kind has an explanation");
                self::assertStringNotContainsString("\u{2014}", (string) $legend[$stem.'_t'], 'no em-dashes in catalogue strings');
            }
        }
    }
}
