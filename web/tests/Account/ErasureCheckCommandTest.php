<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Ops\DailyJobs;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The daily erasure check passes on the real schema and leaves nothing
 * behind (owner 2026-10-11: a running check, not a test that only runs in CI).
 *
 * @see docs/specs/account-and-auth.md §6.3
 */
final class ErasureCheckCommandTest extends KernelTestCase
{
    public function testADeletedAccountLeavesItsAddressNowhereAndTheCheckLeavesNoTrace(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester((new Application($kernel))->find('app:privacy:erasure-check'));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $db = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM users WHERE email LIKE '%@erasure-check.invalid'"));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM content_report WHERE reason = 'Erasure check.'"));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM contact_message WHERE body = 'Erasure check.'"));
    }

    public function testItIsADailyJobTheDashboardWatches(): void
    {
        self::assertContains('app:privacy:erasure-check', DailyJobs::COMMANDS);
    }
}
