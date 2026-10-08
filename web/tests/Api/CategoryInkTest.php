<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\V1\CategoryTable;
use PHPUnit\Framework\TestCase;

/**
 * The glyph colour on a category fill, for both map keys: the same rule as
 * the map's txtOn() (assets/map/util.js), so a key row draws its glyph the
 * colour the map draws it (owner 2026-10-08: the shelter key showed white
 * where the map showed black).
 */
final class CategoryInkTest extends TestCase
{
    public function testDarkFillsGetWhiteAndLightFillsGetInk(): void
    {
        $inks = CategoryTable::inks();

        self::assertSame('#fff', $inks['G'], 'shelter purple');
        self::assertSame('#fff', $inks['D'], 'bike services grey');
        self::assertSame('#14160e', $inks['E'], 'hazard ochre');
        self::assertSame('#14160e', $inks['B'], 'water green');
    }

    public function testTheThresholdIsTheMapsOwn(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../assets/map/util.js');
        self::assertSame(1, preg_match('/\(0\.299\*r\+0\.587\*g\+0\.114\*b\)\/255 < ([\d.]+) \?/', $js, $m));

        foreach (CategoryTable::CATEGORIES as $c) {
            [$r, $g, $b] = array_map('hexdec', str_split(ltrim($c['color'], '#'), 2));
            $white = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 < (float) $m[1];
            self::assertSame($white ? '#fff' : '#14160e', CategoryTable::inks()[$c['letter']], $c['key']);
        }
    }
}
