<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Public holidays count as weekend in the traffic time key
 * (docs/specs/traffic-measurements.md §3.4); the browser reads them from one
 * static file per country.
 */
final class TrafficHolidaysCommandTest extends KernelTestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/cc-holidays-'.bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function execute(array $args): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:traffic:holidays'));
        $tester->execute($args + ['--dir' => $this->dir]);

        return $tester;
    }

    /** @return list<string> */
    private function dates(string $cc): array
    {
        $doc = json_decode((string) file_get_contents($this->dir.'/'.$cc.'.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($cc, $doc['cc']);

        return $doc['dates'];
    }

    public function testEachSupportedCountryGetsItsOfficialHolidays(): void
    {
        $tester = $this->execute(['--from' => '2026', '--to' => '2026']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertContains('2026-04-27', $this->dates('nl'), "King's Day");
        self::assertContains('2026-07-21', $this->dates('be'), 'Belgian National Day');
        self::assertContains('2026-12-25', $this->dates('de'));
        self::assertNotContains('2026-10-05', $this->dates('nl'));
    }

    public function testACountryWithoutAProviderIsNamedAndSkipped(): void
    {
        $tester = $this->execute(['--from' => '2026', '--to' => '2026']);

        self::assertFileDoesNotExist($this->dir.'/cl.json');
        self::assertStringContainsString('cl', $tester->getDisplay());
    }

    public function testDatesAreSortedAndUnique(): void
    {
        $this->execute(['--from' => '2025', '--to' => '2026']);
        $dates = $this->dates('nl');

        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates);
        self::assertSame(array_values(array_unique($dates)), $dates);
        self::assertContains('2025-04-26', $dates, "King's Day falls on the 26th when the 27th is a Sunday");
    }
}
