<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Provider\RefreshCommandLine;
use PHPUnit\Framework\TestCase;

/**
 * The desk prints the refresh command for the environment it is served from
 * (data-provider-hierarchy.md §8). A dev command printed on production is how
 * RIVM stayed unimported there (docs/TODO.md, 2026-09-25).
 */
final class RefreshCommandLineTest extends TestCase
{
    public function testADeveloperMachineGetsTheMakeTarget(): void
    {
        $line = new RefreshCommandLine('dev');

        self::assertFalse($line->onWorkerHost());
        self::assertSame('make provider-run KEY=rivm-drinkwater', $line->dry('rivm-drinkwater'));
        self::assertSame('make provider-run KEY=rivm-drinkwater WRITE=1', $line->write('rivm-drinkwater'));
    }

    public function testProductionRunsTheScriptAgainstItsWorkerDirectory(): void
    {
        $line = new RefreshCommandLine('prod');

        self::assertTrue($line->onWorkerHost());
        self::assertSame('tools/provider-run.sh --worker-dir /opt/workers/cyclingcommons-production rivm-drinkwater', $line->dry('rivm-drinkwater'));
        self::assertSame('tools/provider-run.sh --worker-dir /opt/workers/cyclingcommons-production --write rivm-drinkwater', $line->write('rivm-drinkwater'));
    }

    public function testStagingRunsTheScriptAgainstItsOwnWorkerDirectory(): void
    {
        $line = new RefreshCommandLine('staging');

        self::assertSame('tools/provider-run.sh --worker-dir /opt/workers/cyclingcommons-staging rivm-drinkwater', $line->dry('rivm-drinkwater'));
    }

    public function testAKeyAShellWouldSplitIsQuoted(): void
    {
        self::assertSame("make provider-run KEY='odd key'", (new RefreshCommandLine('dev'))->dry('odd key'));
    }
}
