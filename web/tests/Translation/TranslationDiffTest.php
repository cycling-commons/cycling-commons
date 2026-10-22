<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\TranslationDiff;
use PHPUnit\Framework\TestCase;

/**
 * Word-level was/now segments for curator translation history.
 */
final class TranslationDiffTest extends TestCase
{
    public function testEqualStringsAreOneUnchangedSegment(): void
    {
        self::assertSame(
            [['type' => 'eq', 'text' => 'hello world']],
            TranslationDiff::words('hello world', 'hello world'),
        );
    }

    public function testReplacedWordIsDeletedThenInserted(): void
    {
        $segments = TranslationDiff::words('the old map', 'the new map');

        self::assertSame('eq', $segments[0]['type']);
        self::assertSame('the', $segments[0]['text']);
        self::assertSame('del', $segments[1]['type']);
        self::assertSame('old', $segments[1]['text']);
        self::assertSame('ins', $segments[2]['type']);
        self::assertSame('new', $segments[2]['text']);
        self::assertSame('eq', $segments[3]['type']);
        self::assertSame('map', $segments[3]['text']);
    }

    public function testAroundKeepsAFewWordsBesideEachChange(): void
    {
        $from = 'one two three four five six seven eight nine ten end.';
        $to = 'one two three four five six seven eight nine ten end..';

        self::assertSame([
            ['type' => 'eq', 'text' => '… seven eight nine ten'],
            ['type' => 'del', 'text' => 'end.'],
            ['type' => 'ins', 'text' => 'end..'],
        ], TranslationDiff::around(TranslationDiff::words($from, $to), 4));
    }

    public function testAroundFoldsTheMiddleOfALongUnchangedRun(): void
    {
        $from = 'a b c d e f g h i j';
        $to = 'A b c d e f g h i J';

        self::assertSame([
            ['type' => 'del', 'text' => 'a'],
            ['type' => 'ins', 'text' => 'A'],
            ['type' => 'eq', 'text' => 'b c … h i'],
            ['type' => 'del', 'text' => 'j'],
            ['type' => 'ins', 'text' => 'J'],
        ], TranslationDiff::around(TranslationDiff::words($from, $to), 2));
    }

    public function testALongTextWithOneChangeStaysCheap(): void
    {
        $words = implode(' ', array_map(static fn (int $i): string => 'w'.$i, range(1, 5000)));

        $segments = TranslationDiff::words($words.' end.', $words.' end..');

        self::assertSame(['type' => 'del', 'text' => 'end.'], $segments[\count($segments) - 2]);
        self::assertLessThan(10, \count($segments), 'the shared start is one segment, not a table of 5,000 by 5,000');
    }
}
