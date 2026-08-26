<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
}
