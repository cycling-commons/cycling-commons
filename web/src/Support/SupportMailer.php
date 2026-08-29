<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

use App\Support\Entity\BugReport;
use App\Support\Entity\ContactMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two mails a support form sends, and the one it sends later.
 *
 * **Every send is best-effort.** A dead mail transport must never lose a
 * message or a bug report: the row is written and committed first, the mail is
 * attempted second, and a failure is logged rather than thrown. The desk is the
 * system of record; mail is a courtesy on top of it. Getting that order wrong
 * is how a contact form silently drops everything for a week and nobody
 * notices, which is worse than no contact form at all.
 *
 * **The acknowledgement is not optional.** Somebody who writes to a project and
 * hears nothing assumes it went nowhere, and the second thing they do is give
 * up. It also carries the reference number, which is the only way they can
 * chase it, and the answer-by date for the topics that carry one.
 *
 * @see docs/specs/contact-and-support.md §7
 *
 * @api
 */
final readonly class SupportMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private SupportRecipients $recipients,
        #[Autowire('%env(APP_SITE_URL)%')]
        private string $siteUrl,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
        #[Autowire('%cc.support.from_email%')]
        private string $fromEmail,
        #[Autowire('%cc.organisation.name%')]
        private string $organisationName,
    ) {
    }

    /**
     * The display name on every mail we send.
     *
     * The legal name when one is configured, and the project's own name until
     * then. Never empty: a `From` with a blank display name is what a spam
     * filter reads as a machine, and an acknowledgement that lands in a junk
     * folder is the same as no acknowledgement at all.
     */
    private function fromName(): string
    {
        return '' !== trim($this->organisationName) ? trim($this->organisationName) : 'Cycling Commons';
    }

    /** Tell the sender we have it, in their language, with the reference. */
    public function acknowledgeContact(ContactMessage $message): void
    {
        $locale = $message->getLocale() ?? $this->defaultLocale;
        $reference = $this->contactReference($message);

        $this->send(
            (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName()))
                ->to(new Address($message->getEmail(), $message->getName() ?? ''))
                ->subject($this->trans('support.email.ack_subject', ['%reference%' => $reference], $locale))
                ->htmlTemplate('emails/support_contact_ack.html.twig')
                ->context([
                    'locale' => $locale,
                    'reference' => $reference,
                    'topic_label' => $this->trans('support.topic.'.$message->getTopic()->value, [], $locale),
                    // Neither the body nor the deadline is passed. The body
                    // would make this mail a spam reflector; the deadline is
                    // ours to meet and belongs on the desk notification, where
                    // somebody can act on it.
                    'site_url' => rtrim($this->siteUrl, '/'),
                ]),
            'contact acknowledgement',
            ['contact_message_id' => $message->getId()],
        );
    }

    /** Tell whoever is on duty that something arrived. */
    public function notifyContact(ContactMessage $message): void
    {
        $to = $this->recipients->all();
        if ([] === $to) {
            $this->logger->warning('A contact message arrived with no support recipients configured.', [
                'contact_message_id' => $message->getId(),
            ]);

            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName()))
            ->subject(\sprintf(
                '[Cycling Commons] %s%s',
                $this->trans('support.topic.'.$message->getTopic()->value, [], $this->defaultLocale),
                $message->getTopic()->isOnAClock() ? ' (clock)' : '',
            ))
            ->htmlTemplate('emails/support_contact_notify.html.twig')
            ->context([
                'locale' => $this->defaultLocale,
                'message' => $message,
                'reference' => $this->contactReference($message),
                'desk_url' => rtrim($this->siteUrl, '/').'/moderate/inbox',
            ]);

        // The sender's own address on Reply-To, never on From: sending mail as
        // somebody else's domain is how a support mailbox gets an SPF failure
        // and a spam reputation.
        $email->replyTo(new Address($message->getEmail(), $message->getName() ?? ''));
        foreach ($to as $address) {
            $email->addTo($address);
        }

        $this->send($email, 'contact notification', ['contact_message_id' => $message->getId()]);
    }

    /** Tell whoever is on duty that a bug landed. Critical ones say so in the subject. */
    public function notifyBug(BugReport $report): void
    {
        $to = $this->recipients->all();
        if ([] === $to) {
            $this->logger->warning('A bug report arrived with no support recipients configured.', [
                'bug_report_id' => $report->getId(),
            ]);

            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName()))
            ->subject(\sprintf(
                '[Cycling Commons] %s%s: %s',
                BugSeverity::Critical === $report->getSeverity() ? 'CRITICAL bug' : 'Bug',
                BugArea::Unsure === $report->getArea() ? '' : ' in '.$report->getArea()->value,
                $report->getTitle(),
            ))
            ->htmlTemplate('emails/support_bug_notify.html.twig')
            ->context([
                'locale' => $this->defaultLocale,
                'report' => $report,
                'reference' => $this->bugReference($report),
                'desk_url' => rtrim($this->siteUrl, '/').'/moderate/bugs/'.$report->getId(),
            ]);

        $reporter = $report->getReporterEmail();
        if (null !== $reporter) {
            $email->replyTo(new Address($reporter));
        }
        foreach ($to as $address) {
            $email->addTo($address);
        }

        $this->send($email, 'bug notification', ['bug_report_id' => $report->getId()]);
    }

    /** Tell the reporter their bug was filed, and how to follow it. */
    public function acknowledgeBug(BugReport $report): void
    {
        $to = $report->getReporterEmail();
        if (null === $to) {
            return;
        }

        $locale = $report->getLocale() ?? $this->defaultLocale;

        $this->send(
            (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName()))
                ->to(new Address($to))
                ->subject($this->trans('support.email.bug_ack_subject', [
                    '%reference%' => $this->bugReference($report),
                ], $locale))
                ->htmlTemplate('emails/support_bug_ack.html.twig')
                ->context([
                    'locale' => $locale,
                    'report' => $report,
                    'reference' => $this->bugReference($report),
                    'site_url' => rtrim($this->siteUrl, '/'),
                ]),
            'bug acknowledgement',
            ['bug_report_id' => $report->getId()],
        );
    }

    /**
     * Tell the reporter what happened in the end.
     *
     * Only on {@see BugStatus::Resolved} and {@see BugStatus::Declined}. The
     * once-only rule is NOT enforced here: {@see SupportIntake} stamps
     * `notifiedAt` and flushes it before calling this, so a guard on that stamp
     * in this method would refuse the very send the stamp was set for.
     */
    public function notifyBugOutcome(BugReport $report): void
    {
        $to = $report->getReporterEmail();
        if (null === $to || !$report->getStatus()->notifiesReporter()) {
            return;
        }

        $locale = $report->getLocale() ?? $this->defaultLocale;

        $this->send(
            (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName()))
                ->to(new Address($to))
                ->subject($this->trans('support.email.bug_outcome_subject', [
                    '%reference%' => $this->bugReference($report),
                ], $locale))
                ->htmlTemplate('emails/support_bug_outcome.html.twig')
                ->context([
                    'locale' => $locale,
                    'report' => $report,
                    'reference' => $this->bugReference($report),
                    'status_label' => $this->trans('support.bug.status.'.$report->getStatus()->value, [], $locale),
                    'site_url' => rtrim($this->siteUrl, '/'),
                ]),
            'bug outcome',
            ['bug_report_id' => $report->getId()],
        );
    }

    /** `CC-M-000123`, short enough to read down a phone. */
    public function contactReference(ContactMessage $message): string
    {
        return \sprintf('CC-M-%06d', $message->getId() ?? 0);
    }

    /** `#123`. Delegates: the format lives on the row that has it. */
    public function bugReference(BugReport $report): string
    {
        return $report->getReference();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(TemplatedEmail $email, string $what, array $context): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Logged, never thrown: the row is already committed and the desk
            // will show it whether or not this mail ever leaves the building.
            $this->logger->error('Could not send a support mail ('.$what.').', $context + [
                'transport_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params, string $locale): string
    {
        return $this->translator->trans($key, $params, null, $locale);
    }
}
