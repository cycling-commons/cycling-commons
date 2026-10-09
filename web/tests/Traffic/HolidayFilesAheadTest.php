<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\Command\TrafficHolidaysCommand;
use PHPUnit\Framework\TestCase;

/**
 * The committed holiday files reach far enough ahead (docs/specs/traffic-measurements.md §3.4).
 *
 * The browser counts a public holiday as a weekend day from
 * `public/data/holidays/<cc>.json`. The files are written once and committed,
 * so nothing refreshes them on its own: this test turns red a year before a
 * file runs out, and its message says what to run.
 */
final class HolidayFilesAheadTest extends TestCase
{
    /** How far ahead every file must reach, counted from today. */
    private const string MARGIN = '+12 months';

    private const string DIR = __DIR__.'/../../public/data/holidays';

    public function testEveryHolidayFileReachesAYearAhead(): void
    {
        self::assertSame(
            [],
            self::short(new \DateTimeImmutable('today')),
            "A holiday file ends within a year. Run\n"
            ."    docker exec cycling-commons-dev-app-1 php bin/console app:traffic:holidays\n"
            .'and commit the changed files in web/public/data/holidays (docs/TODO.md, the yearly holiday entry).',
        );
    }

    public function testTheCheckTurnsRedWhenTheFilesRunOut(): void
    {
        // The same check, on a day far past every file: it must name them all.
        self::assertNotSame([], self::short(new \DateTimeImmutable('2100-01-01')));
    }

    public function testEveryCountryWithAProviderHasAFile(): void
    {
        foreach (TrafficHolidaysCommand::PROVIDERS as $cc => $provider) {
            if (null !== $provider) {
                self::assertFileExists(self::DIR."/{$cc}.json", "{$cc} has a holiday provider but no file: run app:traffic:holidays");
            }
        }
    }

    /**
     * The files whose last date falls before `$today` plus the margin, with that date.
     *
     * @return array<string, string>
     */
    private static function short(\DateTimeImmutable $today): array
    {
        $needed = $today->modify(self::MARGIN)->format('Y-m-d');
        $short = [];
        foreach (glob(self::DIR.'/*.json') ?: [] as $file) {
            /** @var array{dates: list<string>} $data */
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            $last = max($data['dates']);
            if ($last < $needed) {
                $short[basename($file)] = $last;
            }
        }
        self::assertNotEmpty(glob(self::DIR.'/*.json'), 'the holiday files are where the browser reads them');

        return $short;
    }
}
