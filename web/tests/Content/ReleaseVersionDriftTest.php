<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\ReleaseNotes;
use PHPUnit\Framework\TestCase;

/**
 * The version a release announces and the version it stamps must agree.
 *
 * A release is four edits in four places: the changelog entry, the git tag, and
 * APP_BUILD_VERSION in each committed per-environment file. On 2026-09-22 three
 * of the four were done - `.env.prod` kept the previous release's number - and
 * nothing noticed, because nothing compares them. That is what this is for.
 *
 * It reads the committed placeholder files only, never a `.local` override, and
 * asserts on one extracted line rather than on file contents.
 *
 * @see docs/specs/roadmap-and-changelog.md
 */
final class ReleaseVersionDriftTest extends TestCase
{
    /** The committed files that stamp the build. `.env` holds the empty default. */
    private const array STAMPED_FILES = ['.env.prod', '.env.staging'];

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

    public function testEveryEnvironmentStampsTheVersionTheChangelogAnnounces(): void
    {
        $announced = ReleaseNotes::RELEASES[0]['version'];

        foreach (self::STAMPED_FILES as $file) {
            self::assertSame(
                'v'.$announced,
                self::stampedVersion($file),
                \sprintf(
                    '%s stamps a different release than the changelog announces (%s). '
                    .'Update APP_BUILD_VERSION there, or the footer and /humans.txt '
                    .'will name a release these notes do not describe.',
                    $file,
                    $announced,
                ),
            );
        }
    }

    /**
     * The base file must stay empty, so the per-environment files decide.
     *
     * A value here would be inherited by every environment that forgot to set
     * its own, which is how a stale number spreads quietly.
     */
    public function testTheBaseEnvFileLeavesTheStampToEachEnvironment(): void
    {
        self::assertSame('', self::stampedVersion('.env'));
    }

    /** A release is only announced once; two entries with one number is a copy-paste. */
    public function testEveryAnnouncedVersionAppearsOnlyOnce(): void
    {
        $versions = array_column(ReleaseNotes::RELEASES, 'version');

        self::assertSame(array_unique($versions), $versions, 'duplicate release version');
    }
}
