<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaTakedownService;
use App\Security\PseudonymousKey;
use App\Support\Entity\ContentReport;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * Filing a report, and answering it.
 *
 * The whole of DSA Articles 16 and 17 for non-photo content, in one seam, so
 * the two halves cannot drift: **the reporter is always answered, and an author
 * whose content was restricted is always told why.**
 *
 * Follows `one-way-to-moderate`: this invents no new moderation mechanics. A
 * curator decides, in a person's own words, and the decision is recorded
 * against their name exactly as every other moderation decision is.
 *
 * The reporter's IP is never stored, only {@see PseudonymousKey}'s salted hash
 * of it, which is what the rate limiter and "is this the same person again"
 * both need and is all they need.
 *
 * @see docs/specs/content-reports.md
 *
 * @api
 */
final class ContentReportService
{
    /** Long enough to explain, short enough to read on a desk. */
    public const int REASON_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        // A photo report on the one withholding ground is handed to the media
        // service rather than reimplemented here: the circuit breaker, the
        // moderation event and the "your photo is hidden" message are all
        // already there and are the part that must not be got wrong
        // (2026-08-30-one-report-route-design.md §3).
        private readonly MediaTakedownService $takedowns,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * Take a notice, acknowledge it, and put it on the desk.
     *
     * The acknowledgement goes out here rather than from the controller because
     * Article 16 requires it "without undue delay" and a controller that
     * forgets is a silent breach. If they left no address there is nothing to
     * send, and that is a valid report too.
     */
    public function file(
        ReportTarget $target,
        string $targetId,
        ReportGround $ground,
        string $reason,
        ?string $contact,
        ?string $label,
        string $reporterIp,
    ): ContentReport {
        $report = new ContentReport(
            Uuid::v4(),
            $target,
            $targetId,
            $ground,
            mb_substr(trim($reason), 0, self::REASON_MAX),
            PseudonymousKey::of('content-report', $reporterIp, $this->secret),
            $this->clock->now(),
        );
        $report->setTargetLabel($label);
        $report->setReporterContact($contact);

        $this->em->persist($report);
        $this->em->flush();

        $this->withholdIfUrgent($report, $target, $targetId, $ground, $reason, $contact, $reporterIp);

        if (null !== $contact) {
            $this->send($contact, 'emails/report_acknowledged.html.twig', 'We have your report', [
                'report' => $report,
            ]);
        }

        return $report;
    }

    /**
     * Hide the picture now, where the ground and the target both allow it.
     *
     * Only intimate imagery or a child, and only on a photo. Everything else
     * waits for a curator, because nothing else can be un-seen by hiding it:
     * pulling a region description offline does not undo somebody reading it.
     *
     * The report row is already saved when this runs, so a failure to withhold
     * loses the withhold and never the report.
     */
    private function withholdIfUrgent(
        ContentReport $report,
        ReportTarget $target,
        string $targetId,
        ReportGround $ground,
        string $reason,
        ?string $contact,
        string $reporterIp,
    ): void {
        if (!$target->canAutoWithhold() || !$ground->autoWithholds()) {
            return;
        }
        if (!Uuid::isValid($targetId)) {
            return;
        }

        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($targetId));
        if (!$upload instanceof MediaUpload) {
            return;
        }

        // The media service keeps its own vocabulary for the event log. The
        // ground's value IS that vocabulary for this one case, on purpose:
        // `intimate_or_child` was carried over whole from MediaTakedownCategory
        // so that the merge needed no mapping table for the urgent path.
        $this->takedowns->report($upload, $ground->value, $reason, $contact, $reporterIp);
    }

    /**
     * Record what a curator decided, then tell everybody who is owed an answer.
     *
     * Order matters: the decision is persisted first, so a mail failure cannot
     * lose it, and the author flag is set only after the message is queued, so
     * a retry cannot tell them twice.
     */
    public function decide(ContentReport $report, ReportStatus $status, string $note, User $curator): void
    {
        $now = $this->clock->now();
        $report->decide($status, trim($note), (int) $curator->getId(), $now);
        $this->em->flush();

        $contact = $report->getReporterContact();
        if (null !== $contact) {
            $this->send($contact, 'emails/report_decided.html.twig', 'About the content you reported', [
                'report' => $report,
            ]);
        }
    }

    /**
     * The Article 17 statement of reasons, to the person who wrote the content.
     *
     * Separate from {@see decide()} because it needs the author, and only the
     * caller that decided knows who that is: the target is polymorphic, so
     * there is no single query that finds them. Idempotent, so a curator who
     * re-opens a report cannot send it twice.
     */
    public function tellAuthor(ContentReport $report, User $author): void
    {
        if (!$report->getStatus()->owesStatementOfReasons() || $report->isAuthorTold()) {
            return;
        }

        $this->send(
            $author->getEmail(),
            'emails/report_statement_of_reasons.html.twig',
            'A decision about something you added',
            ['report' => $report, 'author' => $author],
        );

        $report->markAuthorTold($this->clock->now());
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(string $to, string $template, string $subject, array $context): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
                ->to(new Address($to))
                ->subject($subject)
                ->htmlTemplate($template)
                ->context($context)
        );
    }
}
