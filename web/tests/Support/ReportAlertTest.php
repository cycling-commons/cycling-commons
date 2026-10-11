<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\ContentReportService;
use App\Support\Message\SendReportDigest;
use App\Support\MessageHandler\SendReportDigestHandler;
use App\Support\ReportGround;
use App\Support\ReportTarget;
use App\Support\SupportRecipients;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The support address hears about new reports: the first one at once, the
 * rest of the window in one follow-up mail (owner 2026-10-11).
 *
 * @see docs/specs/contact-and-support.md §7
 */
final class ReportAlertTest extends WebTestCase
{
    private function file(string $reason): void
    {
        static::getContainer()->get(ContentReportService::class)->file(
            ReportTarget::Item,
            '424242',
            ReportGround::Untrue,
            $reason,
            null,
            'Some climb',
            '203.0.113.9',
        );
    }

    /** @return list<Email> the team's report mails sent so far in this request or call */
    private static function alerts(): array
    {
        $out = [];
        foreach (self::getMailerMessages() as $mail) {
            if ($mail instanceof Email && 1 === preg_match('/^\[Cycling Commons\] \d+ new reports?, \d+ open$/', (string) $mail->getSubject())) {
                $out[] = $mail;
            }
        }

        return $out;
    }

    public function testTheFirstReportIsMailedAtOnceAndTheRestWaitForOneFollowUp(): void
    {
        static::createClient();
        $support = array_map(static fn ($a): string => $a->getAddress(), static::getContainer()->get(SupportRecipients::class)->all());
        self::assertNotSame([], $support, 'the test environment has a support address');

        $this->file('The summit sign is gone.');
        $alerts = self::alerts();
        self::assertCount(1, $alerts, 'the first report is mailed at once');
        self::assertSame($support, array_map(static fn ($a): string => $a->getAddress(), $alerts[0]->getTo()));
        self::assertSame('[Cycling Commons] 1 new report, 1 open', $alerts[0]->getSubject());
        $body = (string) $alerts[0]->getHtmlBody();
        self::assertStringNotContainsString('summit sign', $body, 'never the reporter\'s words');
        self::assertStringNotContainsString('Some climb', $body, 'never the reported item\'s name');
        self::assertStringContainsString('/moderate/reports/', $body, 'a link to the report on the desk');

        /** @var InMemoryTransport $queue */
        $queue = static::getContainer()->get('messenger.transport.async');
        $this->file('Second one.');
        $this->file('Third one.');
        self::assertCount(1, self::alerts(), 'no second mail inside the window');
        $digests = array_values(array_filter($queue->getSent(), static fn ($e): bool => $e->getMessage() instanceof SendReportDigest));
        self::assertCount(1, $digests, 'one follow-up for the whole window');
        $delay = $digests[0]->last(DelayStamp::class);
        self::assertNotNull($delay);
        self::assertGreaterThan(0, $delay->getDelay());

        // The window closes: the worker runs the follow-up.
        static::getContainer()->get(SendReportDigestHandler::class)(new SendReportDigest());
        $alerts = self::alerts();
        self::assertCount(2, $alerts);
        self::assertSame('[Cycling Commons] 2 new reports, 3 open', $alerts[1]->getSubject());
    }
}
