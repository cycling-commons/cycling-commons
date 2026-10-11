<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Media\MediaEscalationService;
use App\Media\MediaTakedownService;
use App\Messaging\MessageService;
use App\Moderation\DeskSeen;
use App\Support\ContentReportService;
use App\Support\Entity\ContentReport;
use App\Support\ReportAlert;
use App\Support\ReportGround;
use App\Support\ReportTarget;
use App\Tests\Translation\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A report mail is best effort, like every support mail: a mail server that is
 * down is logged, and the report it was about still stands (audit 2026-10-04).
 *
 * @see docs/specs/content-reports.md §6
 */
final class ReportMailBestEffortTest extends WebTestCase
{
    public function testAFailedAcknowledgementLeavesTheReportFiled(): void
    {
        static::createClient();
        $c = static::getContainer();
        $logger = new RecordingLogger();
        $service = new ContentReportService(
            $c->get(EntityManagerInterface::class),
            $c->get(ReportAlert::class),
            new class implements MailerInterface {
                public function send(RawMessage $message, ?Envelope $envelope = null): void
                {
                    throw new TransportException('Connection refused');
                }
            },
            $logger,
            $c->get(ClockInterface::class),
            $c->get(MediaTakedownService::class),
            $c->get(MediaEscalationService::class),
            $c->get(DeskSeen::class),
            $c->get(MessageService::class),
            'test-secret',
            'noreply@example.test',
            '',
        );

        $report = $service->file(ReportTarget::Item, '515151', ReportGround::Untrue, 'The tap is gone.', 'reporter@example.test', null, '203.0.113.10');

        self::assertInstanceOf(ContentReport::class, $c->get(EntityManagerInterface::class)->find(ContentReport::class, $report->getId()));
        self::assertNotSame([], $logger->records, 'the failed send is logged');
    }
}
