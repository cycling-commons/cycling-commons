<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\Command\TrafficDumpCommand;
use App\Traffic\SafetyPool;
use App\Traffic\SafetyStore;
use App\Traffic\TrafficPool;
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
        $store->addToTotal([
            'way' => 40486875, 'region' => null, 'dir' => 'f', 'label' => 'p', 'band' => 2, 'dayType' => 'workday',
            'quarter' => '2026-Q3', 'dayGroup' => 4,
            'distanceM' => 439, 'timeS' => 53, 'passes' => 0, 'nearby' => 6, 'avgSpeedKmh' => 29.8, 'carSpeedBins' => null,
        ]);
        $pool = static::getContainer()->get(TrafficPool::class);
        $pool->add(TrafficStoreTest::line(['way' => 40486875]));

        $tester = new CommandTester($this->command());
        $tester->execute(['--way' => '40486875']);

        $out = $tester->getDisplay();
        self::assertStringContainsString('nearby', $out);
        self::assertMatchesRegularExpression('/\|\s*0\s*\|\s*6\s*\|/', $out, 'passing 0, nearby 6');
        self::assertMatchesRegularExpression('/\|\s*lines\s*\|\s*day groups\s*\|/', $out);
        self::assertStringContainsString('traffic_pool', $out, 'the waiting room is shown too');
        self::assertDoesNotMatchRegularExpression('/rider|voice/', $out, 'no rider anywhere');
    }

    private function command(): TrafficDumpCommand
    {
        $c = static::getContainer();

        return new TrafficDumpCommand($c->get(TrafficStore::class), $c->get(TrafficPool::class), $c->get(SafetyStore::class), $c->get(SafetyPool::class));
    }

    /** Felt safety (§10): its counts and its waiting room, decrypted, beside the traffic tables. */
    public function testFeltSafetyShowsItsCountsAndItsWaitingRoom(): void
    {
        self::bootKernel();
        $mark = SafetyPoolTest::mark(['way' => 40486875]);
        static::getContainer()->get(SafetyStore::class)->addToTotals([$mark, ['felt' => 'safe'] + $mark]);
        static::getContainer()->get(SafetyPool::class)->add(['band' => 1] + $mark);

        $tester = new CommandTester($this->command());
        $tester->execute(['--way' => '40486875']);

        $out = $tester->getDisplay();
        self::assertStringContainsString('safety_total', $out);
        self::assertMatchesRegularExpression('/\|\s*1\s*\|\s*1\s*\|/', $out, 'safe 1, unsafe 1');
        self::assertStringContainsString('safety_pool', $out);
        self::assertStringContainsString('unsafe', $out, 'the waiting mark, decrypted');
    }
}
