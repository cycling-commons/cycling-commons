<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Command\RetireBakedLengthCommand;
use PHPUnit\Framework\TestCase;

/**
 * Baked "Length" display strings turned back into metres
 * (docs/specs/account-and-auth.md §9).
 *
 * The decision is pure, so it is tested without a database: what matters is
 * which strings become numbers, which are refused, and what is never
 * overwritten.
 */
final class RetireBakedLengthTest extends TestCase
{
    public function testAKilometreStringBecomesMetres(): void
    {
        [$updated, $metres, $note] = RetireBakedLengthCommand::retire([
            'record' => [['label' => 'Length', 'value' => '2.2 km']],
            'headline' => '2.2 km · 7.6% avg',
        ]);

        self::assertNull($note);
        self::assertSame(2200, $metres);
        self::assertSame(2200, $updated['length']);
        // Both baked display strings go: they were two renderings of this number.
        self::assertArrayNotHasKey('record', $updated);
        self::assertArrayNotHasKey('headline', $updated);
    }

    /** A measured length was taken off the drawn line; a seed string never wins over it. */
    public function testAMeasuredLengthIsNeverOverwritten(): void
    {
        [$updated, $metres, $note] = RetireBakedLengthCommand::retire([
            'record' => [['label' => 'Length', 'value' => '1.1 km']],
            'length' => 1117.0,
        ]);

        self::assertNull($note);
        self::assertNull($metres);
        self::assertSame(1117.0, $updated['length']);
        self::assertArrayNotHasKey('record', $updated);
    }

    /** Other baked rows are somebody else's problem — only Length is consumed. */
    public function testOtherRecordRowsSurvive(): void
    {
        [$updated] = RetireBakedLengthCommand::retire([
            'record' => [
                ['label' => 'Length', 'value' => '1.7 km'],
                ['label' => 'Famous for', 'value' => 'Liège–Bastogne–Liège'],
            ],
        ]);

        self::assertSame([['label' => 'Famous for', 'value' => 'Liège–Bastogne–Liège']], $updated['record']);
        self::assertSame(1700, $updated['length']);
    }

    public function testAnItemWithoutABakedLengthIsLeftAlone(): void
    {
        self::assertNull(RetireBakedLengthCommand::retire([
            'record' => [['label' => 'Famous for', 'value' => 'LBL']],
            'headline' => 'Legendary Ardennes climb',
        ]));
        self::assertNull(RetireBakedLengthCommand::retire(['length' => 4236.0]));
    }

    /**
     * An editorial headline on an item we are not touching stays: it is
     * somebody's writing, not a stale rendering of a number.
     */
    public function testAnUnreadableValueIsReportedAndNothingChanges(): void
    {
        [$updated, $metres, $note] = RetireBakedLengthCommand::retire([
            'record' => [['label' => 'Length', 'value' => 'about 2k']],
            'headline' => 'Legendary Ardennes climb',
        ]);

        self::assertNull($updated);
        self::assertNull($metres);
        self::assertNotNull($note);
        self::assertStringContainsString('about 2k', $note);
    }

    /**
     * @return list<array{0: string, 1: ?int}>
     */
    public static function lengthStrings(): array
    {
        return [
            ['2.2 km', 2200],
            ['4,4 km', 4400],     // comma decimal, as fr/nl/de write it
            ['900 m', 900],
            ['1,600 m', 1600],    // thousands separator
            ['1.600 m', 1600],
            [' 12 km ', 12000],
            ['2.2 mi', null],     // never guessed: nothing stores miles
            ['about 2k', null],
            ['1,2,3 km', null],
            ['0 km', null],
            ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lengthStrings')]
    public function testTheParserIsStrict(string $value, ?int $expected): void
    {
        self::assertSame($expected, RetireBakedLengthCommand::metresFrom($value));
    }
}
