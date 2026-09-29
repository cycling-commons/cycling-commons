<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\ReleaseNotes;
use PHPUnit\Framework\TestCase;

/**
 * The version a release announces and the version it stamps must agree.
 *
 * A release names its version in three places: the changelog entry, the git
 * tag, and APP_BUILD_VERSION in `.env`. The stamp lives in `.env` alone because
 * staging runs each release before production, on the same tagged commit, so
 * the two never rightly differ. Nothing else compares these three.
 *
 * It reads the committed placeholder files only, never a `.local` override, and
 * asserts on one extracted line rather than on file contents.
 *
 * @see docs/specs/roadmap-and-changelog.md
 */
final class ReleaseVersionDriftTest extends TestCase
{
    /** Committed environment files that leave the stamp to `.env`. */
    private const array UNSTAMPED_FILES = ['.env.prod', '.env.staging', '.env.test'];

    private static function webDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** The single APP_BUILD_VERSION assignment in a committed env file. */
    private static function stampedVersion(string $file): ?string
    {
        $path = self::webDir().'/'.$file;
        self::assertFileExists($path);

        $matched = preg_match(
            '/^APP_BUILD_VERSION=(.*)$/m',
            (string) file_get_contents($path),
            $m,
        );

        return 1 === $matched ? trim($m[1], " \t\"'") : null;
    }

    public function testTheStampNamesTheVersionTheChangelogAnnounces(): void
    {
        $announced = ReleaseNotes::RELEASES[0]['version'];

        self::assertSame(
            'v'.$announced,
            self::stampedVersion('.env'),
            \sprintf(
                '.env stamps a different release than the changelog announces (%s). '
                .'Update APP_BUILD_VERSION there, or the footer and /humans.txt '
                .'will name a release these notes do not describe.',
                $announced,
            ),
        );
    }

    /**
     * No environment file carries its own copy.
     *
     * A second copy is a second place to forget at release time, which is how
     * a stale number reaches one environment quietly.
     */
    public function testNoEnvironmentFileOverridesTheStamp(): void
    {
        foreach (self::UNSTAMPED_FILES as $file) {
            self::assertNull(
                self::stampedVersion($file),
                $file.' sets APP_BUILD_VERSION; the release name belongs in .env alone.',
            );
        }
    }

    /** A release is only announced once; two entries with one number is a copy-paste. */
    public function testEveryAnnouncedVersionAppearsOnlyOnce(): void
    {
        $versions = array_column(ReleaseNotes::RELEASES, 'version');

        self::assertSame(array_unique($versions), $versions, 'duplicate release version');
    }
}
