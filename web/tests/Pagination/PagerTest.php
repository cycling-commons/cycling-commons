<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Pagination;

use App\Pagination\Pager;
use PHPUnit\Framework\TestCase;

/**
 * The one page-arithmetic helper every paged list shares.
 *
 * Worth its own test because the clamping is the part that keeps a bookmarked
 * `?page=` from rendering nothing after the list behind it shrank — a curator
 * comes back to a desk they cleared, and "page 4 of 1" must resolve to the
 * last page rather than to an empty screen.
 */
final class PagerTest extends TestCase
{
    public function testAMiddlePageKnowsBothNeighbours(): void
    {
        $p = Pager::of(2, 60, 25);

        self::assertSame(2, $p['page']);
        self::assertSame(3, $p['pages']);
        self::assertSame(60, $p['total']);
        self::assertSame(25, $p['offset']);
        self::assertSame(1, $p['prev']);
        self::assertSame(3, $p['next']);
    }

    public function testTheEdgesHaveNoStepBeyondThem(): void
    {
        self::assertNull(Pager::of(1, 60, 25)['prev']);
        self::assertNull(Pager::of(3, 60, 25)['next']);
    }

    /** A bookmark into a list that has since shrunk lands on the last page. */
    public function testAnOutOfRangePageClampsToTheLast(): void
    {
        $p = Pager::of(99, 60, 25);

        self::assertSame(3, $p['page']);
        self::assertSame(50, $p['offset']);
        self::assertNull($p['next']);
    }

    /** `?page=0`, `?page=-4` and a missing parameter all mean page one. */
    public function testAPageBelowOneClampsUp(): void
    {
        self::assertSame(1, Pager::of(0, 60, 25)['page']);
        self::assertSame(1, Pager::of(-4, 60, 25)['page']);
        self::assertSame(0, Pager::of(-4, 60, 25)['offset']);
    }

    /**
     * An empty list is one page, not zero. `pages: 0` would make the partial's
     * "page 1 of 0" read as a bug, and would make `page > pages` true for the
     * only page there is.
     */
    public function testAnEmptyListIsStillOnePage(): void
    {
        $p = Pager::of(1, 0, 25);

        self::assertSame(1, $p['pages']);
        self::assertSame(1, $p['page']);
        self::assertNull($p['prev']);
        self::assertNull($p['next']);
    }

    public function testAPartialLastPageStillCounts(): void
    {
        self::assertSame(3, Pager::of(1, 51, 25)['pages']);
    }

    /** A zero or negative page size would divide by zero; it means one. */
    public function testAnImpossiblePageSizeIsFloored(): void
    {
        $p = Pager::of(1, 3, 0);

        self::assertSame(1, $p['perPage']);
        self::assertSame(3, $p['pages']);
    }
}
