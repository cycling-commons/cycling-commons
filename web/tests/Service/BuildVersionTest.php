<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BuildVersion;
use PHPUnit\Framework\TestCase;

/**
 * The footer's build stamp, and every rung of its fallback ladder.
 *
 * The `--match 'v*'` case matters most: the repo carries non-release utility
 * tags (backup markers), and the day one of those became the public footer
 * would be the day this derivation earned distrust.
 */
final class BuildVersionTest extends TestCase
{
    protected function setUp(): void
    {
        BuildVersion::reset();
    }

    protected function tearDown(): void
    {
        BuildVersion::reset();
    }

    public function testAReleaseTagBecomesTheStamp(): void
    {
        $v = new BuildVersion('/repo/web', '', static fn (string $cmd): ?string => str_contains($cmd, 'describe') ? 'v0.2.0' : '2026-08-09');

        self::assertSame(['number' => 'v0.2.0', 'date' => '2026-08-09'], $v->stamp());
    }

    public function testTheCommandAsksOnlyForReleaseShapedTags(): void
    {
        $seen = [];
        $v = new BuildVersion('/repo/web', '', static function (string $cmd) use (&$seen): ?string {
            $seen[] = $cmd;

            return str_contains($cmd, 'describe') ? 'v0.2.0-14-gabc1234' : '2026-08-09';
        });

        self::assertSame('v0.2.0-14-gabc1234', $v->stamp()['number']);
        self::assertStringContainsString("--match 'v*'", $seen[0]);
    }

    /** web/ has no .git in some layouts; the repo root one level up does. */
    public function testFallsThroughToTheRepoRoot(): void
    {
        $v = new BuildVersion('/repo/web', '', static function (string $cmd): ?string {
            if (str_contains($cmd, "'/repo/web'") && !str_contains($cmd, "'/repo'".'')) {
                return null;   // no .git here
            }

            return str_contains($cmd, 'describe') ? 'abc1234' : '2026-08-09';
        });

        self::assertSame('abc1234', $v->stamp()['number']);
    }

    public function testNoGitAnywhereFallsBackToTheEnvThenDev(): void
    {
        $none = static fn (string $cmd): ?string => null;

        BuildVersion::reset();
        self::assertSame(['number' => 'v9.9-manual', 'date' => ''], (new BuildVersion('/x', 'v9.9-manual', $none))->stamp());

        BuildVersion::reset();
        self::assertSame(['number' => 'dev', 'date' => ''], (new BuildVersion('/x', '', $none))->stamp());
    }

    /** Once per worker: the second call must not exec again. */
    public function testTheStampIsComputedOnce(): void
    {
        $calls = 0;
        $v = new BuildVersion('/repo', '', static function (string $cmd) use (&$calls): ?string {
            ++$calls;

            return str_contains($cmd, 'describe') ? 'v1.0.0' : '2026-08-09';
        });

        $v->stamp();
        $v->stamp();

        self::assertSame(2, $calls);   // describe + date, exactly once each
    }
}
