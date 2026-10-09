<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\PublicBugWording;
use PHPUnit\Framework\TestCase;

/**
 * Public bug wording names nobody (rulebook RB-BUG-07): the reporter's address
 * is always refused, their account name only as a whole word.
 */
final class PublicBugWordingTest extends TestCase
{
    public function testTheAddressIsFoundWhateverItsCase(): void
    {
        self::assertSame(PublicBugWording::ADDRESS, PublicBugWording::check('Reported by Ann@Example.test', 'ann@example.test', null));
    }

    public function testTheNameIsFoundAsAWholeWord(): void
    {
        self::assertSame(PublicBugWording::NAME, PublicBugWording::check('Ann Rider cannot save', null, 'ann rider'));
    }

    public function testANameInsideAnotherWordIsNoMatch(): void
    {
        self::assertNull(PublicBugWording::check('Annotations vanish', null, 'Ann'));
    }

    public function testANameShorterThanThreeLettersIsIgnored(): void
    {
        self::assertNull(PublicBugWording::check('Jo can see it', null, 'Jo'));
    }

    public function testTheAddressWinsOverTheName(): void
    {
        self::assertSame(PublicBugWording::ADDRESS, PublicBugWording::check('Ann ann@example.test', 'ann@example.test', 'Ann'));
    }

    public function testCleanWordingPasses(): void
    {
        self::assertNull(PublicBugWording::check('The map stays grey', 'ann@example.test', 'Ann'));
    }
}
