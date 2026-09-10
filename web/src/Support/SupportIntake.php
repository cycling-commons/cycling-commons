<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
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
        private MessageService $messages,
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

        // A reporter with an account hears back THROUGH the account: the
        // outcome lands in /messages and the mail that follows is a
        // notification of it. Two reasons that is the right shape.
        //
        // 1. **A form post leaves nothing in a sent folder.** Somebody who
        //    reported a bug and deleted the mail has no record at all; their
        //    own messages are the record.
        // 2. **The address is ours to know, not theirs to type.** It is the
        //    account address, changed in settings, so a report cannot be used
        //    to send our mail to an address a stranger chose.
        //
        // MessageService::sendSystem persists the message and queues the
        // notification; the outbox mails it after the request.
        $userId = $report->getUserId();
        if (null !== $userId) {
            $this->messages->sendSystem(
                $userId,
                UserMessageKind::BugOutcome,
                'bug',
                (int) $report->getId(),
                $report->getReference().' · '.$report->getTitle(),
                'messages.body.bug_outcome',
                ['%status%' => $report->getStatus()->value],
                $report->getOutcomeNote(),
            );
            $this->em->flush();

            return;
        }

        // No account: the address they typed is the only way to reach them.
        $this->mailer->notifyBugOutcome($report);
    }
}
