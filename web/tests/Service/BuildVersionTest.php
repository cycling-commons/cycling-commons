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

        foreach ($this->cleanup as $dir) {
            @unlink($dir.'/REVISION');
            @rmdir($dir.'/web');
            @rmdir($dir);
        }
        $this->cleanup = [];
    }

    /**
     * A deployed release has no .git — the deploy script records the commit in
     * REVISION and strips it. Reading that file is what keeps a hardened host
     * (shell_exec in disable_functions) from taking every page down over a
     * footer, which is exactly what happened on 2026-08-21.
     */
    public function testTheRevisionFileIsPreferredOverGit(): void
    {
        $dir = $this->tempRelease("abc1234567890abcdef\n");

        // A $run that would answer if asked — proving it is never asked.
        $v = new BuildVersion($dir, '', static fn (string $cmd): ?string => 'v9.9.9-from-git');

        self::assertSame('abc123456789', $v->stamp()['number'], 'twelve chars of the recorded sha');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $v->stamp()['date']);
    }

    /**
     * The monorepo layout: the Symfony app is in web/, so REVISION sits one
     * level above the kernel's project dir.
     */
    public function testTheRevisionFileIsFoundOneLevelAbove(): void
    {
        $release = $this->tempRelease("fedcba9876543210\n");
        $app = $release.'/web';
        mkdir($app);

        $v = new BuildVersion($app, '', static fn (string $cmd): ?string => null);

        self::assertSame('fedcba987654', $v->stamp()['number']);
    }

    /** An empty or whitespace-only file is not an answer — keep falling. */
    public function testABlankRevisionFileIsIgnored(): void
    {
        $dir = $this->tempRelease("   \n");

        $v = new BuildVersion($dir, 'v1.2-env', static fn (string $cmd): ?string => null);

        self::assertSame(['number' => 'v1.2-env', 'date' => ''], $v->stamp());
    }

    /**
     * The seam returns null when the function is unavailable — which is what a
     * disabled shell_exec looks like to function_exists(). Every git rung must
     * then fall through rather than throw.
     */
    public function testAnUnavailableShellFallsAllTheWayThrough(): void
    {
        $v = new BuildVersion('/nonexistent', '', static fn (string $cmd): ?string => null);

        self::assertSame(['number' => 'dev', 'date' => ''], $v->stamp());
    }

    private function tempRelease(string $revision): string
    {
        $dir = sys_get_temp_dir().'/ccbv'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/REVISION', $revision);
        $this->cleanup[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $cleanup = [];

    public function testAReleaseTagBecomesTheStamp(): void
    {
        $v = new BuildVersion('/repo/web', '', static fn (string $cmd): ?string => str_contains($cmd, 'describe') ? 'v0.2.0' : '2026-08-09');

        self::assertSame(['number' => 'v0.2.0', 'date' => '2026-08-09'], $v->stamp());
    }

    /**
     * The release plan is pre-release-first (owner, 2026-08-09): live opens at
     * v0.8.0-beta, moves to v1.0.0-beta when everything is in, and v1.0.0 is
     * the first full release. The suffix must pass through untouched — a
     * derivation that "cleaned" it would misstate what is deployed.
     */
    public function testAPreReleaseTagPassesThroughUntouched(): void
    {
        $v = new BuildVersion('/repo/web', '', static fn (string $cmd): ?string => str_contains($cmd, 'describe') ? 'v0.8.0-beta' : '2026-09-01');

        self::assertSame(['number' => 'v0.8.0-beta', 'date' => '2026-09-01'], $v->stamp());
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
