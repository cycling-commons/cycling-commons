<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

use App\Support\Entity\BugReport;
use App\Support\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Write it down first, then try to tell anybody about it.
 *
 * Both public forms funnel through here so that the order is the same and
 * cannot drift: **persist, flush, then mail.** A mail transport that is down,
 * misconfigured, or rate-limited must never be able to lose a bug report or a
 * message that starts a legal clock. Mail is best-effort on top of a committed
 * row ({@see SupportMailer}), and the desk is the system of record.
 *
 * The flush also has to happen before the mail because the acknowledgement
 * carries the reference number, and the reference number is the row's id.
 *
 * @see docs/specs/contact-and-support.md §7
 *
 * @api
 */
final readonly class SupportIntake
{
    public function __construct(
        private EntityManagerInterface $em,
        private SupportMailer $mailer,
    ) {
    }

    public function receiveContact(ContactMessage $message): void
    {
        $this->em->persist($message);
        $this->em->flush();

        $this->mailer->acknowledgeContact($message);
        $this->mailer->notifyContact($message);
    }

    public function receiveBug(BugReport $report): void
    {
        $this->em->persist($report);
        $this->em->flush();

        $this->mailer->acknowledgeBug($report);
        $this->mailer->notifyBug($report);
    }

    /**
     * A curator moved a bug, and the reporter may be owed the outcome.
     *
     * The stamp is set here, before the send, and flushed with it: a mail that
     * fails is logged and not retried, which is the right trade for "we fixed
     * your bug": sending it twice is worse than not sending it, because the
     * second one arrives long after the reporter has moved on and reads as a
     * system that has lost track of itself.
     */
    public function announceBugOutcome(BugReport $report): void
    {
        if (!$report->getStatus()->notifiesReporter() || null !== $report->getNotifiedAt() || !$report->isAnswerable()) {
            $this->em->flush();

            return;
        }

        $report->markNotified(new \DateTimeImmutable());
        $this->em->flush();

        $this->mailer->notifyBugOutcome($report);
    }
}
