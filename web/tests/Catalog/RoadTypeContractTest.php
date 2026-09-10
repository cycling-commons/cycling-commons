<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\RoadType;
use PHPUnit\Framework\TestCase;

/**
 * The road-type vocabulary is stated twice — in PHP, which owns what the form
 * offers, and in the map module, which turns a tile's `highway` tag into the
 * same words. Neither can read the other at runtime, so drift shows up as a
 * drawer that says "Local road" beside a dropdown that has never heard of it.
 */
final class RoadTypeContractTest extends TestCase
{
    private const MODULE = __DIR__.'/../../assets/map/surface-tiles.js';

    /** @return array<string, string> the JS ROAD_TYPE map, resolved to labels */
    private function jsMapping(): array
    {
        $js = file_get_contents(self::MODULE);
        self::assertIsString($js);
        self::assertSame(1, preg_match('/const ROAD_TYPE = \{(.*?)\};/s', $js, $m), 'ROAD_TYPE must stay a literal map');
        preg_match_all('/(\w+):\s*\'(\w+)\'/', $m[1], $pairs, \PREG_SET_ORDER);

        // The JS side holds dictionary KEYS ('roadMain'); PHP holds the English
        // labels. Map one to the other here rather than in the module, so the
        // module keeps being able to translate.
        $keyToLabel = [
            'roadMain' => 'Main road', 'roadLocal' => 'Local road',
            'roadResidential' => 'Residential street', 'roadTrack' => 'Farm or forest track',
            'roadPath' => 'Path or trail', 'roadCycleway' => 'Cycleway',
        ];
        $out = [];
        foreach ($pairs as [, $highway, $key]) {
            self::assertArrayHasKey($key, $keyToLabel, "unknown dictionary key {$key}");
            $out[$highway] = $keyToLabel[$key];
        }

        return $out;
    }

    public function testTheMapAndTheFormAgreeOnEveryHighwayValue(): void
    {
        $php = RoadType::FROM_HIGHWAY;
        $js = $this->jsMapping();
        ksort($php);
        ksort($js);

        self::assertSame($php, $js);
    }

    public function testEveryMappedKindIsOneTheFormOffers(): void
    {
        foreach (RoadType::FROM_HIGHWAY as $highway => $kind) {
            self::assertContains($kind, RoadType::DECLARABLE, "highway={$highway}");
        }
    }

    public function testAnUnknownHighwayGetsNoGuess(): void
    {
        // A value this vocabulary does not cover means OSM said something we
        // have no honest translation for. Inventing 'Local road' would print a
        // guess in the same typeface as a fact.
        self::assertNull(RoadType::fromHighway('motorway'));
        self::assertNull(RoadType::fromHighway('raceway'));
        self::assertNull(RoadType::fromHighway(''));
    }

    public function testUnclassifiedIsNotShownAsUnclassified(): void
    {
        // It is a British road CLASS meaning "a public road below tertiary",
        // and every rider outside mapping reads it as "nobody classified this".
        self::assertSame('Local road', RoadType::fromHighway('unclassified'));
        self::assertNotContains('unclassified', RoadType::DECLARABLE);
    }
}
