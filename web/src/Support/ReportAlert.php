<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Support\Message\SendReportDigest;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Tell the support address that reports arrived, at most once per window.
 *
 * The first report after a quiet window is mailed at once. Every report in
 * the next {@see self::WINDOW} seconds waits for one follow-up mail, sent by a
 * delayed message when the window closes, so a flood of reports is one mail
 * and no report goes unmentioned. Each mail names the reports nobody was told
 * about yet (`content_report.team_told_at`) and the open total. The window
 * lives in the shared app cache, so the two web hosts keep one between them.
 *
 * @see docs/specs/contact-and-support.md §7
 * @see docs/specs/content-reports.md
 *
 * @api
 */
final readonly class ReportAlert
{
    /** Seconds between two mails. */
    public const int WINDOW = 900;

    /** Unix time before which no mail goes at once. */
    private const string NEXT_KEY = 'report_alert.next';

    /** Set while a follow-up mail is queued, so a window queues one. */
    private const string PENDING_KEY = 'report_alert.pending';

    /** The most reports one mail lists; the rest are a count. */
    public const int LIST_MAX = 20;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private SupportRepository $reports,
        private SupportMailer $mailer,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /** A report was committed. */
    public function reportFiled(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $next = $this->cache->getItem(self::NEXT_KEY);
        $nextAt = $next->isHit() ? (int) $next->get() : 0;
        if ($now >= $nextAt) {
            $this->send();

            return;
        }

        $pending = $this->cache->getItem(self::PENDING_KEY);
        if ($pending->isHit()) {
            return;
        }
        $this->cache->save($pending->set(true)->expiresAfter($nextAt - $now + 60));
        $this->bus->dispatch(new SendReportDigest(), [new DelayStamp(($nextAt - $now) * 1000)]);
    }

    /** The queued follow-up: whatever arrived while the window was closed. */
    public function sendDigest(): void
    {
        $this->cache->deleteItem(self::PENDING_KEY);
        $this->send();
    }

    private function send(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->cache->save($this->cache->getItem(self::NEXT_KEY)->set($now + self::WINDOW)->expiresAfter(self::WINDOW));

        $untold = $this->reports->claimUntoldReports(self::LIST_MAX);
        if (0 === $untold['count']) {
            return;
        }
        $this->mailer->notifyReports($untold['reports'], $untold['count'], $this->reports->openReportCount());
    }
}
