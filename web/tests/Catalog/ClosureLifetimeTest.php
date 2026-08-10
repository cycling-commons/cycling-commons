<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ClosureLifetime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClosureLifetimeTest extends TestCase
{
    private const string OBSERVED = '2026-03-01 12:00:00';

    /** @return iterable<string, array{string, string}> */
    public static function windows(): iterable
    {
        yield 'today expires in two days' => ['Today', '2026-03-03'];
        yield 'days expires in ten' => ['Days', '2026-03-11'];
        yield 'weeks expires in six' => ['Weeks', '2026-04-12'];
        yield 'months expires in six' => ['Months', '2026-08-28'];
    }

    #[DataProvider('windows')]
    public function testEachStatedWindowHasItsOwnExpiry(string $answer, string $expected): void
    {
        $at = ClosureLifetime::expiresAt(
            ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => $answer],
            new \DateTimeImmutable(self::OBSERVED),
        );

        self::assertSame($expected, $at->format('Y-m-d'));
    }

    /**
     * The whole point of Manifesto §VII: "unknown" must not quietly mean
     * "forever", which is exactly what a missing branch would give it.
     */
    public function testUnknownExpiresRatherThanLastingForever(): void
    {
        $at = ClosureLifetime::expiresAt(
            ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Unknown'],
            new \DateTimeImmutable(self::OBSERVED),
        );

        self::assertSame('2026-08-28', $at->format('Y-m-d'));
    }

    /**
     * A closure with no stated duration at all — the shape a legacy row or a
     * half-filled form produces — is the one most likely to sit on the map for
     * ever, so it gets the same bounded window rather than being skipped.
     */
    public function testAnAbsentOrUnrecognisedAnswerFallsBackToTheBoundedWindow(): void
    {
        $absent = ClosureLifetime::expiresAt(['hazardType' => ClosureLifetime::CLOSED_TYPE], new \DateTimeImmutable(self::OBSERVED));
        $nonsense = ClosureLifetime::expiresAt(['closedFor' => 'whenever'], new \DateTimeImmutable(self::OBSERVED));

        self::assertSame('2026-08-28', $absent->format('Y-m-d'));
        self::assertSame('2026-08-28', $nonsense->format('Y-m-d'));
    }

    public function testOnlyClosuresOnLetterFExpire(): void
    {
        $closed = ['hazardType' => ClosureLifetime::CLOSED_TYPE];

        self::assertTrue(ClosureLifetime::applies('F', $closed));
        // Same attributes, wrong letter — nothing outside hazards decays.
        self::assertFalse(ClosureLifetime::applies('C', $closed));
        // A hazard that is not a closure keeps its place: ice and crosswind
        // have no stated end date, so there is nothing to expire against.
        self::assertFalse(ClosureLifetime::applies('F', ['hazardType' => 'Ice / frost']));
        self::assertFalse(ClosureLifetime::applies('F', []));
    }

    /** The form vocabulary and the duration table must not drift apart. */
    public function testEveryOfferedChoiceHasAWindow(): void
    {
        foreach (ClosureLifetime::CHOICES as $choice) {
            self::assertArrayHasKey($choice, ClosureLifetime::DAYS, $choice.' is offered on the form but has no window');
        }
        self::assertSame(
            [],
            array_diff(array_keys(ClosureLifetime::DAYS), ClosureLifetime::CHOICES),
            'a window exists for a value the form never offers',
        );
    }
}
