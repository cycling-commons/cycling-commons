<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\SafetyDisclosure;
use PHPUnit\Framework\TestCase;

/**
 * Felt safety is shown only with enough marks, and only as a band
 * (docs/specs/traffic-measurements.md §10): safe from two thirds safe,
 * unsafe from two thirds unsafe, mixed between. Below the rule nothing comes
 * out, not even an empty entry.
 */
final class SafetyDisclosureTest extends TestCase
{
    /** @param array<string, mixed> $over */
    private static function row(array $over = []): array
    {
        return $over + ['way' => 7, 'band' => 3, 'dayType' => 'workday', 'quarter' => '2026-Q4', 'label' => 'r', 'region' => null, 'safe' => 0, 'unsafe' => 5];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function evaluate(array $rows, string $scheme = 'daytype'): array
    {
        return new SafetyDisclosure()->evaluate($rows, $scheme, '2024-Q1');
    }

    public function testFiveMarksAreShownAsABand(): void
    {
        self::assertSame([['way' => 7, 'group' => 'workday', 'felt' => 'unsafe']], self::evaluate([self::row()]));
    }

    public function testFourMarksShowNothingAtAll(): void
    {
        self::assertSame([], self::evaluate([self::row(['unsafe' => 4])]));
    }

    public function testTwoThirdsDecideTheBand(): void
    {
        self::assertSame('safe', self::evaluate([self::row(['safe' => 4, 'unsafe' => 2])])[0]['felt']);
        self::assertSame('unsafe', self::evaluate([self::row(['safe' => 2, 'unsafe' => 4])])[0]['felt']);
        self::assertSame('mixed', self::evaluate([self::row(['safe' => 3, 'unsafe' => 3])])[0]['felt']);
        self::assertSame('mixed', self::evaluate([self::row(['safe' => 3, 'unsafe' => 2])])[0]['felt'], 'three fifths is not two thirds');
    }

    public function testRowsOfOneGroupAddUp(): void
    {
        $shown = self::evaluate([
            self::row(['band' => 1, 'unsafe' => 2]),
            self::row(['band' => 4, 'unsafe' => 2, 'quarter' => '2026-Q3']),
            self::row(['band' => 2, 'safe' => 1, 'unsafe' => 0]),
        ]);

        self::assertSame([['way' => 7, 'group' => 'workday', 'felt' => 'unsafe']], $shown, 'the daytype scheme joins the parts of the day and the quarters');
    }

    public function testTheGroupsAreTheTrafficSchemes(): void
    {
        $rows = [self::row(), self::row(['dayType' => 'weekend', 'safe' => 5, 'unsafe' => 0])];

        self::assertSame([['way' => 7, 'group' => 'all', 'felt' => 'mixed']], self::evaluate($rows, 'all'));
        self::assertSame(['workday', 'weekend'], array_column(self::evaluate($rows), 'group'));
        self::assertSame(['workday|evening', 'weekend|evening'], array_column(self::evaluate($rows, 'daytype_band'), 'group'));
    }

    public function testRowsBeforeThePeriodAreLeftOut(): void
    {
        self::assertSame([], self::evaluate([self::row(['quarter' => '2023-Q4'])]));
    }

    public function testAnEntryNamesNoCountAndNoDirection(): void
    {
        $entry = self::evaluate([self::row()])[0];

        self::assertSame(['way', 'group', 'felt'], array_keys($entry));
    }
}
