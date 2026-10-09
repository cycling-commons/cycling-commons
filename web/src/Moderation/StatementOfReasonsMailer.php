<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The email that carries a statement of reasons.
 *
 * Two callers: {@see \App\Messaging\MessageMailer} for a rider's message that
 * carries one (sent after the decision has committed), and the administrator's
 * account decisions, where there is no inbox left to write to.
 *
 * Sent from the no-reply sender with `Reply-To` set to the address the contact
 * page publishes, as every report mail is (content-reports.md §6), because the
 * statement's redress line is "reply to this email". When that address is
 * empty the statement points to the contact page instead.
 *
 * Every line arrives translated in the recipient's language
 * ({@see StatementOfReasons::lines()}): this can run outside a request, where
 * the translator's own locale is whatever the last request left.
 *
 * @see docs/specs/content-reports.md §7
 *
 * @api
 */
final readonly class StatementOfReasonsMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_SITE_URL)%')]
        private string $siteUrl,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
        #[Autowire('%cc.support.from_email%')]
        private string $fromEmail,
        #[Autowire('%cc.support.public_email%')]
        private string $replyTo,
    ) {
    }

    /**
     * Send it. A transport failure is logged and dropped: for a rider the
     * message row is the record, and an account decision is in the admin log.
     *
     * @return bool whether the mail was handed to the transport
     */
    public function send(string $address, string $name, ?string $locale, ?string $timeZone, StatementOfReasons $statement): bool
    {
        if ('' === trim($address)) {
            return false;
        }
        $locale ??= $this->defaultLocale;
        $replyTo = trim($this->replyTo);
        $lines = $statement->lines($this->translator, $locale, '' !== $replyTo, $timeZone ?? 'Europe/Amsterdam');
        $site = rtrim($this->siteUrl, '/');

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, 'Cycling Commons'))
            ->to(new Address($address, $name))
            ->subject('[Cycling Commons] '.$lines['headline'])
            ->htmlTemplate('emails/statement_of_reasons.html.twig')
            ->textTemplate('emails/statement_of_reasons.txt.twig')
            ->context([
                's' => $lines,
                'greeting' => $this->translator->trans('dsa_statement.email.greeting', ['%name%' => $name], null, $locale),
                'intro' => $this->translator->trans('dsa_statement.email.intro', [], null, $locale),
                'signoff' => $this->translator->trans('dsa_statement.email.signoff', [], null, $locale),
                'labels' => $this->labels($locale),
                'terms_url' => StatementOfReasons::termsUrl($site, $locale),
                'contact_url' => StatementOfReasons::contactUrl($site, $locale),
                'answer_url' => null !== $statement->answerPath ? $site.$statement->answerPath : null,
                'can_reply' => '' !== $replyTo,
                'locale' => $locale,
            ]);
        if ('' !== $replyTo) {
            $email->replyTo(new Address($replyTo));
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not email a statement of reasons.', [
                'reference' => $statement->reference,
                'decision' => $statement->decision->value,
                'transport_error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The headings of the statement, in one language.
     *
     * @return array<string, string>
     */
    public function labels(string $locale): array
    {
        $labels = [];
        foreach (['what', 'why', 'rule', 'how', 'contest', 'reference', 'terms_link', 'contact_link'] as $key) {
            $labels[$key] = $this->translator->trans('dsa_statement.label.'.$key, [], null, $locale);
        }
        $labels['answer_link'] = $this->translator->trans('dsa_statement.copyright_answer_link', [], null, $locale);

        return $labels;
    }
}
