<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Vote\PlaceRanker;
use PHPUnit\Framework\TestCase;

final class PlaceRankerTest extends TestCase
{
    /** @return array{subjectId: int, votes: int, points: int, handicapped: bool, winsBefore: int} */
    private static function t(int $id, int $points, bool $handicapped = false, int $wins = 0, int $votes = 1): array
    {
        return ['subjectId' => $id, 'votes' => $votes, 'points' => $points, 'handicapped' => $handicapped, 'winsBefore' => $wins];
    }

    /**
     * @param list<array{subjectId: int, votes: int, points: int, handicapped: bool, winsBefore: int, score: int, place: ?int, position: int}> $placed
     *
     * @return array<int, ?int>
     */
    private static function places(array $placed): array
    {
        $out = [];
        foreach ($placed as $p) {
            $out[$p['subjectId']] = $p['place'];
        }

        return $out;
    }

    public function testTheWorkedExampleFromTheSpec(): void
    {
        // route-domain.md §8c: Ardennes, spring 2028, climbs, 7 voters, in points.
        // A (1) won last spring, D (4) was third: both count x0.75.
        $placed = PlaceRanker::rank([self::t(1, 18, true, 1), self::t(2, 14), self::t(3, 10), self::t(4, 7, true)], 7);

        self::assertSame([2, 1, 3, 4], array_column($placed, 'subjectId'));
        self::assertSame([2 => 1, 1 => 1, 3 => 3, 4 => 4], self::places($placed));
        // Quarter points: 14 x 4, 18 x 3, 10 x 4, 7 x 3.
        self::assertSame([56, 54, 40, 21], array_column($placed, 'score'));
        self::assertSame([1, 2, 3, 4], array_column($placed, 'position'));
    }

    public function testExactlyOnePointApartDoesNotShare(): void
    {
        self::assertSame([1 => 1, 2 => 2], self::places(PlaceRanker::rank([self::t(1, 5), self::t(2, 4)], 5)));
    }

    public function testEqualAfterTheHandicapShares(): void
    {
        // 4 x 0.75 = 3 against 3.
        self::assertSame([1 => 1, 2 => 1], self::places(PlaceRanker::rank([self::t(1, 4, true), self::t(2, 3)], 5)));

        // Inside a shared place, the item that won fewer times before goes first.
        $placed = PlaceRanker::rank([self::t(1, 4, true, 2), self::t(2, 3, false, 0)], 5);
        self::assertSame([2, 1], array_column($placed, 'subjectId'));
    }

    public function testASharedPlaceIsMeasuredFromItsFirstItemNotChained(): void
    {
        // 5, 4.5 and 4 points: the second is within a point of the first, the
        // third is within a point of the second but a full point below the first.
        $placed = PlaceRanker::rank([self::t(1, 5), self::t(2, 6, true), self::t(3, 4)], 6);
        self::assertSame([1 => 1, 2 => 1, 3 => 3], self::places($placed));
    }

    public function testBelowTheThresholdNothingIsPlaced(): void
    {
        $placed = PlaceRanker::rank([self::t(1, 3), self::t(2, 1)], 4);
        self::assertSame([1 => null, 2 => null], self::places($placed));
        self::assertSame([1, 2], array_column($placed, 'position'));
    }

    public function testAnEmptyListStaysEmpty(): void
    {
        self::assertSame([], PlaceRanker::rank([], 9));
    }

    public function testEqualScoresPutTheItemMoreRidersVotedForFirst(): void
    {
        // 8 points from two riders (5 + 3) against 8 from four (3 + 3 + 1 + 1).
        $placed = PlaceRanker::rank([self::t(1, 8, votes: 2), self::t(2, 8, votes: 4)], 5);
        self::assertSame([2, 1], array_column($placed, 'subjectId'));
    }
}
