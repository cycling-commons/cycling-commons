<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\SurfaceVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * The confirmable surface classes are stated twice — once in the map module
 * that draws the button, once in PHP where the query value is translated and
 * validated. Neither side can read the other at runtime, so this test is what
 * keeps them honest: drift would give the rider a "This is correct" button
 * that opens a form with nothing chosen, which looks like a bug in the form
 * rather than in the list.
 */
final class SurfaceConfirmClassContractTest extends TestCase
{
    private const MODULE = __DIR__.'/../../assets/map/surface-tiles.js';

    public function testTheMapOffersExactlyTheClassesPhpCanConfirm(): void
    {
        $js = file_get_contents(self::MODULE);
        self::assertIsString($js);
        self::assertSame(1, preg_match('/export const CONFIRMABLE_CLASSES = \[([^\]]*)\];/', $js, $m),
            'CONFIRMABLE_CLASSES must stay a literal array — a computed list cannot be checked from here');

        preg_match_all("/'([^']+)'/", $m[1], $found);
        $fromJs = $found[1];
        sort($fromJs);
        $fromPhp = array_keys(SurfaceVocabulary::TILE_CLASS);
        sort($fromPhp);

        self::assertSame($fromPhp, $fromJs);
    }

    public function testEveryConfirmableClassMapsToADeclarableLabel(): void
    {
        // A class that maps to a label the curator dropdown does not offer is
        // dropped silently by the controller, which is the same invisible
        // failure by another route.
        foreach (SurfaceVocabulary::TILE_CLASS as $cls => $label) {
            self::assertContains($label, SurfaceVocabulary::DECLARABLE, "tile class {$cls}");
            self::assertSame($label, SurfaceVocabulary::fromTileClass($cls));
        }
    }

    public function testAClassThatClaimsNoSurfaceConfirmsNothing(): void
    {
        // `cycleway` states what a way IS; `unverified` is the absence of a
        // claim. Both must return null rather than a plausible-looking guess.
        self::assertNull(SurfaceVocabulary::fromTileClass('cycleway'));
        self::assertNull(SurfaceVocabulary::fromTileClass('unverified'));
        self::assertNull(SurfaceVocabulary::fromTileClass(''));
        self::assertNull(SurfaceVocabulary::fromTileClass('Gravel'), 'labels are not classes');
    }
}
