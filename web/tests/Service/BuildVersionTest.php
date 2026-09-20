<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
            @unlink($dir.'/VERSION');
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
        // The FULL sha, not the twelve shown: the footer's source link resolves
        // it, and a forge is free to refuse an abbreviation.
        self::assertSame('abc1234567890abcdef', $v->stamp()['commit']);
    }

    /**
     * The deploy script can record `git describe` in VERSION before it strips
     * .git. That name is the release the footer shows; the commit stays
     * REVISION's, so the source link still names the running build.
     */
    public function testAVersionFileBesideRevisionNamesTheRelease(): void
    {
        $dir = $this->tempRelease("abc1234567890abcdef\n");
        file_put_contents($dir.'/VERSION', "v0.8.0-beta\n");

        $v = new BuildVersion($dir, 'v0.0.0-from-env', static fn (string $cmd): ?string => null);

        self::assertSame('v0.8.0-beta', $v->stamp()['number']);
        self::assertSame('abc1234567890abcdef', $v->stamp()['commit']);
    }

    /**
     * No VERSION file: the environment's label names the release (the owner
     * bumps APP_BUILD_VERSION with the tag, 2026-09-20). Still REVISION's sha
     * underneath, and never the label as the link.
     */
    public function testTheEnvLabelNamesARevisionReleaseWithoutAVersionFile(): void
    {
        $dir = $this->tempRelease("abc1234567890abcdef\n");

        $v = new BuildVersion($dir, 'v0.8.0-beta', static fn (string $cmd): ?string => null, 'https://forge.test/r');

        self::assertSame('v0.8.0-beta', $v->stamp()['number']);
        self::assertSame('abc1234567890abcdef', $v->stamp()['commit']);
        self::assertSame('https://forge.test/r/commit/abc1234567890abcdef', $v->stamp()['url']);
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

        self::assertSame(['number' => 'v1.2-env', 'date' => '', 'commit' => '', 'url' => ''], $v->stamp());
    }

    /**
     * The seam returns null when the function is unavailable — which is what a
     * disabled shell_exec looks like to function_exists(). Every git rung must
     * then fall through rather than throw.
     */
    public function testAnUnavailableShellFallsAllTheWayThrough(): void
    {
        $v = new BuildVersion('/nonexistent', '', static fn (string $cmd): ?string => null);

        self::assertSame(['number' => 'dev', 'date' => '', 'commit' => '', 'url' => ''], $v->stamp());
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
        $v = new BuildVersion('/repo/web', '', self::git('v0.2.0', '2026-08-09'));

        self::assertSame(
            ['number' => 'v0.2.0', 'date' => '2026-08-09', 'commit' => '1111111111111111111111111111111111111111', 'url' => '/commit/1111111111111111111111111111111111111111'],
            $v->stamp(),
        );
    }

    /**
     * The release plan is pre-release-first (owner, 2026-08-09): live opens at
     * v0.8.0-beta, moves to v1.0.0-beta when everything is in, and v1.0.0 is
     * the first full release. The suffix must pass through untouched — a
     * derivation that "cleaned" it would misstate what is deployed.
     */
    public function testAPreReleaseTagPassesThroughUntouched(): void
    {
        $v = new BuildVersion('/repo/web', '', self::git('v0.8.0-beta', '2026-09-01'));

        self::assertSame(
            ['number' => 'v0.8.0-beta', 'date' => '2026-09-01', 'commit' => '1111111111111111111111111111111111111111', 'url' => '/commit/1111111111111111111111111111111111111111'],
            $v->stamp(),
        );
    }

    public function testTheCommandAsksOnlyForReleaseShapedTags(): void
    {
        $seen = [];
        $v = new BuildVersion('/repo/web', '', static function (string $cmd) use (&$seen): ?string {
            $seen[] = $cmd;

            return (self::git('v0.2.0-14-gabc1234', '2026-08-09'))($cmd);
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

            return (self::git('abc1234', '2026-08-09'))($cmd);
        });

        self::assertSame('abc1234', $v->stamp()['number']);
    }

    public function testNoGitAnywhereFallsBackToTheEnvThenDev(): void
    {
        $none = static fn (string $cmd): ?string => null;

        BuildVersion::reset();
        self::assertSame(['number' => 'v9.9-manual', 'date' => '', 'commit' => '', 'url' => ''], (new BuildVersion('/x', 'v9.9-manual', $none))->stamp());

        BuildVersion::reset();
        self::assertSame(['number' => 'dev', 'date' => '', 'commit' => '', 'url' => ''], (new BuildVersion('/x', '', $none))->stamp());
    }

    /** Once per worker: the second call must not exec again. */
    public function testTheStampIsComputedOnce(): void
    {
        $calls = 0;
        $v = new BuildVersion('/repo', '', static function (string $cmd) use (&$calls): ?string {
            ++$calls;

            return (self::git('v1.0.0', '2026-08-09'))($cmd);
        });

        $v->stamp();
        $v->stamp();

        self::assertSame(3, $calls);   // describe + date + rev-parse, exactly once each
    }

    /**
     * A working copy that cannot name its HEAD still stamps: the number and the
     * date are what the footer prints, and the missing commit only costs the
     * source link its precision (it falls back to the repository root rather
     * than disappearing). A footer must never 500 over a git call.
     */
    public function testAMissingRevParseLeavesTheCommitEmpty(): void
    {
        $v = new BuildVersion('/repo', '', static fn (string $cmd): ?string => match (true) {
            str_contains($cmd, 'rev-parse') => null,
            str_contains($cmd, 'describe') => 'v1.0.0',
            default => '2026-08-09',
        });

        self::assertSame(['number' => 'v1.0.0', 'date' => '2026-08-09', 'commit' => '', 'url' => ''], $v->stamp());
    }

    /**
     * The source offer is built from the commit, and falls back to the
     * repository root when there is none. Both halves matter: a link that names
     * no build points at whatever HEAD is, which stops being the served code
     * the moment a box is hotfixed, and a build with no link offers nothing to
     * fetch (AGPL section 13). The footer and /humans.txt both read this, so
     * deriving it in either of them would let the two disagree.
     */
    public function testTheSourceUrlPinsTheRunningCommit(): void
    {
        $repo = 'https://example.org/org/repo/';   // trailing slash on purpose

        $v = new BuildVersion('/repo', '', self::git('v1.0.0', '2026-08-09'), $repo);
        self::assertSame('https://example.org/org/repo/commit/'.str_repeat('1', 40), $v->stamp()['url']);

        BuildVersion::reset();
        $noCommit = new BuildVersion('/x', 'v9.9-manual', static fn (string $cmd): ?string => null, $repo);
        self::assertSame('https://example.org/org/repo', $noCommit->stamp()['url'], 'no commit falls back to the root');
    }

    /**
     * A git working copy, answering all three questions BuildVersion asks.
     *
     * @return callable(string): ?string
     */
    private static function git(string $describe, string $date): callable
    {
        return static fn (string $cmd): ?string => match (true) {
            str_contains($cmd, 'rev-parse') => str_repeat('1', 40),
            str_contains($cmd, 'describe') => $describe,
            default => $date,
        };
    }
}
