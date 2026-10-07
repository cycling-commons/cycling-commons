<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\Command\TrafficDumpCommand;
use App\Traffic\TrafficStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The development dump (docs/specs/traffic-measurements.md §4.3): the way a
 * developer reads the encrypted tables, so it shows every counted field.
 */
final class TrafficDumpCommandTest extends KernelTestCase
{
    public function testNearbyCarsShowBesidePassingOnes(): void
    {
        self::bootKernel();
        $store = static::getContainer()->get(TrafficStore::class);
        $store->addToCell([
            'way' => 40486875, 'dir' => 'f', 'label' => 'p', 'slot' => 57, 'dayType' => 'workday',
            'season' => 'autumn', 'quarter' => '2026-Q3', 'day' => 20724,
            'distanceM' => 439, 'timeS' => 53, 'passes' => 0, 'nearby' => 6, 'avgSpeedKmh' => 29.8, 'carSpeedBins' => null,
        ]);

        $tester = new CommandTester(new TrafficDumpCommand($store));
        $tester->execute(['--way' => '40486875']);

        $out = $tester->getDisplay();
        self::assertStringContainsString('nearby', $out);
        self::assertMatchesRegularExpression('/\|\s*0\s*\|\s*6\s*\|/', $out, 'passing 0, nearby 6');
    }
}
