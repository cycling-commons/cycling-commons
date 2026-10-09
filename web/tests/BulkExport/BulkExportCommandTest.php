<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportBuilder;
use App\BulkExport\BulkExportStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:export:build`, the command the worker host's weekly timer runs
 * (docs/specs/operations.md §1).
 */
final class BulkExportCommandTest extends KernelTestCase
{
    public function testTheCommandPublishesASnapshotAndSaysWhatItHolds(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:export:build'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        /** @var array{snapshot: string} $latest */
        $latest = json_decode(static::getContainer()->get(BulkExportStorage::class)->read('latest.json'), true, 512, \JSON_THROW_ON_ERROR);
        $output = $tester->getDisplay();
        self::assertStringContainsString($latest['snapshot'], $output);
        self::assertStringContainsString('places.geojson.gz', $output);
        self::assertStringContainsString('routes.geojson.gz', $output);
    }

    public function testTheCommandSaysSoWhenAnotherBuildIsRunning(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:export:build'));
        $other = DriverManager::getConnection(static::getContainer()->get(Connection::class)->getParams());
        try {
            $other->fetchOne('SELECT pg_advisory_lock(hashtext(:k))', ['k' => BulkExportBuilder::LOCK]);

            $tester->execute([]);
        } finally {
            $other->close();
        }

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already running', $tester->getDisplay());
        self::assertFalse(static::getContainer()->get(BulkExportStorage::class)->has('latest.json'), 'nothing published');
    }
}
