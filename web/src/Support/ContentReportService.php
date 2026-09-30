<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaEscalationService;
use App\Media\MediaTakedownService;
use App\Media\MediaTakedownSource;
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
        // (content-reports.md §9).
        private readonly MediaTakedownService $takedowns,
        // Escalating from the reports desk is the same act as on every other
        // desk, so it is the same service (photo-uploads.md §6d).
        private readonly MediaEscalationService $escalations,
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

        $this->raiseMediaTakedown($target, $targetId, $ground, $reason, $contact, $reporterIp);

        if (null !== $contact) {
            $this->send($contact, 'emails/report_acknowledged.html.twig', 'We have your report', [
                'report' => $report,
            ]);
        }

        return $report;
    }

    /**
     * Raise the media takedown request behind a photo report.
     *
     * EVERY ground, not only the urgent one. The report row is the DSA record
     * and carries the mails; the takedown request is the operational state, and
     * a curator can only grant or decline a request that exists. Without this a
     * photo reported for, say, advertising would leave the desk with a decision
     * to record and no picture to act on.
     *
     * The urgent ground still hides the file on the spot, because the media
     * service does that itself: `MediaTakedownService::report()` checks the
     * category, spends the circuit-breaker budget, detaches the object and
     * tells the uploader. All of that stays where it has been running since
     * August, and the ground's value IS that service's vocabulary for the one
     * case where it matters, since `intimate_or_child` was carried over whole.
     *
     * The report row is already saved when this runs, so a failure here loses
     * the takedown and never the report.
     */
    private function raiseMediaTakedown(
        ReportTarget $target,
        string $targetId,
        ReportGround $ground,
        string $reason,
        ?string $contact,
        string $reporterIp,
    ): void {
        if (!$target->canAutoWithhold() || !Uuid::isValid($targetId)) {
            return;
        }

        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($targetId));
        if (!$upload instanceof MediaUpload) {
            return;
        }

        $this->takedowns->report($upload, $ground->value, $reason, $contact, $reporterIp);
    }

    /**
     * The photo a report is about, when it is about one that still has a row.
     */
    public function photoOf(ContentReport $report): ?MediaUpload
    {
        if (!$report->getTargetType()->canAutoWithhold() || !Uuid::isValid($report->getTargetId())) {
            return null;
        }

        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($report->getTargetId()));

        return $upload instanceof MediaUpload ? $upload : null;
    }

    /**
     * Is the photo this report is about under legal hold?
     *
     * Then only an administrator acts on it (photo-uploads.md §6d), and the
     * report waits: no curator can decide it until the hold is lifted.
     */
    public function isHeld(ContentReport $report): bool
    {
        return true === $this->photoOf($report)?->isEscalated();
    }

    /**
     * Can this report be closed as `Moot`?
     *
     * Not while a takedown waits on its photo. Every photo report raises one,
     * the reports desk is the only place it is decided, and one takedown at a
     * time is the rule: a moot close would leave a hidden photo hidden, on no
     * desk, and blocking every later report. Upheld or Rejected answers it.
     */
    public function canBeMoot(ContentReport $report): bool
    {
        return true !== $this->photoOf($report)?->isTakedownPending();
    }

    /**
     * Why this status cannot be recorded on this report, as a translation key, or null when it can.
     *
     * Only a photo report is ever refused, because only there does the
     * decision carry through to the thing itself (content-reports.md §9).
     */
    public function refusal(ContentReport $report, ReportStatus $status): ?string
    {
        if (!$report->getTargetType()->canAutoWithhold()) {
            return null;
        }

        $upload = $this->photoOf($report);
        if (null === $upload) {
            // Already gone: Moot is what is left, and Upheld would remove nothing.
            return ReportStatus::Upheld === $status ? 'report.desk.refused_nothing_to_remove' : null;
        }

        return match (true) {
            $upload->isEscalated() => 'report.desk.refused_held',
            ReportStatus::Moot === $status && $upload->isTakedownPending() => 'report.desk.refused_moot_pending',
            ReportStatus::Upheld === $status && null !== $upload->getObjectsDeletedAt() => 'report.desk.refused_nothing_to_remove',
            default => null,
        };
    }

    /**
     * Carry a decision about a photo through to the photo itself.
     *
     * The desk records what a curator decided; for a picture that decision has
     * to move a file as well. Upheld removes the photo, whatever the takedown
     * slot holds ({@see MediaTakedownService::removeOnReport()}); rejected
     * declines a waiting third-party takedown, which puts back anything that
     * was withheld while it was waiting. `Moot` does neither: it is only offered
     * when nothing waits on the photo, which is the whole meaning of that
     * outcome.
     *
     * @throws ReportDecisionRefused when upheld removed nothing
     */
    private function carryDecisionToPhoto(ContentReport $report, ReportStatus $status, string $note, User $curator): void
    {
        $upload = $this->photoOf($report);
        if (null === $upload) {
            return;
        }

        if (ReportStatus::Upheld === $status && !$this->takedowns->removeOnReport($upload, $curator, $note)) {
            throw new ReportDecisionRefused('report.desk.refused_nothing_to_remove');
        }
        // Only a report's own takedown. An uploader's request about their own
        // photo is decided on the takedowns desk, and rejecting somebody
        // else's report must not republish what the uploader asked to remove.
        if (ReportStatus::Rejected === $status && MediaTakedownSource::ThirdParty === $upload->getTakedownSource()) {
            $this->takedowns->decline($upload, $curator, $note);
        }
    }

    /**
     * Escalate the photo a report is about as suspected illegal content.
     *
     * The same act as on the takedowns and submission desks
     * ({@see MediaEscalationService::escalate()}): hidden from everybody,
     * held against every deletion path, and an administrator alerted. The
     * report itself stays undecided and waits: nobody is mailed, and
     * {@see refusal()} keeps curators from deciding it while the hold lasts.
     *
     * @throws ReportDecisionRefused     when the report is not about a photo that can still be held
     * @throws \InvalidArgumentException when the curator gave no reason, or too long a one
     */
    public function escalate(ContentReport $report, User $curator, string $reason): void
    {
        $upload = $this->photoOf($report);
        if (null === $upload || null !== $upload->getObjectsDeletedAt()) {
            throw new ReportDecisionRefused('report.desk.escalate_nothing_to_hold');
        }

        $this->escalations->escalate($upload, $curator, $reason);
    }

    /** Move a report between its waiting states. Nothing is sent: the reporter hears from us when it is decided. */
    public function takeUp(ContentReport $report, ReportStatus $status = ReportStatus::InProgress): void
    {
        $report->takeUp($status);
        $this->em->flush();
    }

    /**
     * Record what a curator decided, then tell everybody who is owed an answer.
     *
     * A photo decision moves the photo first and is recorded only once it
     * has: an upheld report that removed nothing is refused, so no reporter or
     * author is ever told "upheld" about a photo that is still there.
     *
     * The decision is persisted before any mail, so a mail failure cannot lose
     * it, and the author flag is set only after the message is queued, so a
     * retry cannot tell them twice.
     *
     * @throws ReportDecisionRefused when {@see refusal()} names a reason, before anything is saved or sent
     */
    public function decide(ContentReport $report, ReportStatus $status, string $note, User $curator): void
    {
        $refusal = $this->refusal($report, $status);
        if (null !== $refusal) {
            throw new ReportDecisionRefused($refusal);
        }

        $this->carryDecisionToPhoto($report, $status, trim($note), $curator);

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
