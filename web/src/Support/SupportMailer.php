<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Community\Entity\CountryInterest;
use App\Community\Entity\CuratorApplication;
use App\Entity\User;
use App\Support\Entity\BugReport;
use App\Support\Entity\ContactMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two mails a support form sends, and the one it sends later. Plus the two
 * that tell the support address a rider applied to curate or asked for a
 * country, so neither waits unseen on an admin page nobody has open.
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
        private UrlGeneratorInterface $urls,
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
                // The address alone: the name is the sender's own typing,
                // and a To display name is text we would deliver to whoever
                // owns that address.
                ->to(new Address($message->getEmail()))
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

    /**
     * Tell whoever is on duty that somebody offered to curate.
     *
     * Sent once per application, after it is committed. The reviewer answers
     * on the applications desk; this mail only says that something is waiting
     * there and carries enough to judge how urgent it is.
     *
     * @param ?string $regionName the requested region's name, when the application names one that exists
     */
    public function notifyCuratorApplication(CuratorApplication $application, User $applicant, ?string $regionName): void
    {
        $to = $this->recipients->all();
        if ([] === $to) {
            $this->logger->warning('A curator application arrived with no support recipients configured.', [
                'curator_application_id' => $application->getId(),
            ]);

            return;
        }

        $country = $this->countryName($application->getCountryCode());
        $place = $regionName ?? ('' !== $application->getRequestedArea() ? $application->getRequestedArea() : null);

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName()))
            ->subject(\sprintf(
                '[Cycling Commons] Curator application: %s%s',
                null !== $place ? $place.', ' : '',
                $country,
            ))
            ->htmlTemplate('emails/curator_application_notify.html.twig')
            ->context([
                'locale' => $this->defaultLocale,
                'application' => $application,
                'applicant' => $applicant,
                'country_name' => $country,
                'region_name' => $regionName,
                'profile_url' => $applicant->isPublicProfile() && null !== $applicant->getUuid()
                    ? rtrim($this->siteUrl, '/').$this->urls->generate('rider_profile', [
                        'uuid' => $applicant->getUuid()->toRfc4122(),
                        '_locale' => $this->defaultLocale,
                    ])
                    : null,
                'desk_url' => rtrim($this->siteUrl, '/').$this->urls->generate('admin_curator_applications'),
            ]);

        $email->replyTo(new Address($applicant->getEmail(), $applicant->getDisplayName()));
        foreach ($to as $address) {
            $email->addTo($address);
        }

        $this->send($email, 'curator application notification', ['curator_application_id' => $application->getId()]);
    }

    /**
     * Tell whoever is on duty that a rider asked for a country or an area.
     *
     * The caller decides whether a request is new enough to mail about
     * ({@see \App\Community\CountryInterestService::record()}); this only
     * writes it.
     *
     * @param int $countryTotal every request on file for this country, this one included
     */
    public function notifyCountryRequest(CountryInterest $interest, User $requester, int $countryTotal): void
    {
        $to = $this->recipients->all();
        if ([] === $to) {
            $this->logger->warning('A country request arrived with no support recipients configured.', [
                'country_interest_id' => $interest->getId(),
            ]);

            return;
        }

        $country = $this->countryName($interest->getCountryCode());
        $area = $interest->getRegionName();

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName()))
            ->subject(\sprintf(
                '[Cycling Commons] Country request: %s%s%s',
                '' !== $area ? $area.', ' : '',
                $country,
                $interest->isWillingToCurate() ? ' (would curate)' : '',
            ))
            ->htmlTemplate('emails/country_request_notify.html.twig')
            ->context([
                'locale' => $this->defaultLocale,
                'interest' => $interest,
                'requester' => $requester,
                'country_name' => $country,
                'country_total' => $countryTotal,
                'desk_url' => rtrim($this->siteUrl, '/').$this->urls->generate('admin_country_requests'),
            ]);

        $email->replyTo(new Address($requester->getEmail(), $requester->getDisplayName()));
        foreach ($to as $address) {
            $email->addTo($address);
        }

        $this->send($email, 'country request notification', ['country_interest_id' => $interest->getId()]);
    }

    /** The country's English name for the team, or the code when Intl has none. */
    private function countryName(string $code): string
    {
        return Countries::exists($code) ? Countries::getName($code, $this->defaultLocale) : $code;
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

    /**
     * The reply the desk offers for Fixed, in the language
     * {@see notifyBugOutcome()} writes to this reporter in. It lives here so
     * the desk and the mail cannot disagree about which language that is.
     */
    public function bugOutcomeDefaultNote(BugReport $report): string
    {
        return $this->trans('support.email.bug_outcome_default_note', [], $report->getLocale() ?? $this->defaultLocale);
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
