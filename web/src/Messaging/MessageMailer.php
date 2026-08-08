<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Delivers a dashboard message to its recipient's inbox — M7.
 *
 * The dashboard was always the record; this is the delivery channel on top of
 * it (moderation-and-contribution.md §7.8). Until now a curator could ask a
 * rider a question and the rider would only ever find out by logging back in
 * and looking, which for most people means never. That made the needs-info loop
 * — the one exchange in the whole system that is *waiting* on somebody — the
 * least likely to complete.
 *
 * Three rules:
 *
 * - **One wording, not two.** The subject and body come from the same
 *   `messages.kind_*` / `messages.body.*` catalogue keys the dashboard renders,
 *   translated into the recipient's own locale. An email that paraphrased the
 *   page would drift from it, and then the rider has two slightly different
 *   accounts of one decision.
 * - **The email is a pointer, not a copy.** It carries the headline, the
 *   curator's note if there is one, and a link. It never carries the photo, and
 *   it never carries anything the dashboard would not show the same person.
 * - **A failure is logged, never fatal.** The message row is the record and it
 *   is already written; a dead mail transport must not roll anything back or
 *   surface as an error to whoever triggered it.
 *
 * @api Called by MessageMailSubscriber on terminate.
 */
final readonly class MessageMailer
{
    /**
     * Kinds that are NOT emailed.
     *
     * `RiderReply` is addressed to the deciding curator, and a curator working
     * a queue gets a page that already shows it — mailing every reply would
     * turn a desk into an inbox. Everything else here is a decision about, or a
     * question for, one person's own contribution, which is exactly what email
     * is for.
     */
    private const array SILENT = [UserMessageKind::RiderReply];

    public function __construct(
        private MailerInterface $mailer,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_SITE_URL)%')]
        private string $siteUrl,
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
    ) {
    }

    public function deliver(UserMessage $message): void
    {
        if (\in_array($message->getKind(), self::SILENT, true)) {
            return;
        }

        /* The transaction that created this may have rolled back after it was
           queued. Re-reading is what makes the outbox safe: no row, no mail,
           and no rider told their submission was approved when it was not. */
        $id = $message->getId();
        if (null === $id || null === $this->em->find(UserMessage::class, $id)) {
            return;
        }

        $user = $this->em->find(User::class, $message->getUserId());
        if (!$user instanceof User) {
            return;   // account deleted between the decision and terminate
        }
        $email = $user->getEmail();
        if ('' === $email) {
            return;
        }

        // The recipient's own language, not the language of whoever triggered
        // the decision. A Dutch rider must not get a German email because a
        // German curator happened to be on the desk.
        $locale = $user->getLocale() ?? $this->defaultLocale;

        $headline = $this->translator->trans('messages.kind_'.$message->getKind()->value, [], null, $locale);
        $bodyKey = $message->getBodyKey();
        $body = null === $bodyKey
            ? null
            : $this->translator->trans($bodyKey, $message->getBodyParams() ?? [], null, $locale);

        try {
            $this->mailer->send(
                (new TemplatedEmail())
                    ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
                    ->to(new Address($email, $user->getDisplayName()))
                    ->subject('[Cycling Commons] '.$headline)
                    ->htmlTemplate('emails/message.html.twig')
                    ->context([
                        'headline' => $headline,
                        'body' => $body,
                        // The curator's own words, when there are any. This is
                        // the part a rider actually needs on a needs-info.
                        'note' => $message->getBodyText(),
                        'reference' => $message->getRefLabel(),
                        'messages_url' => rtrim($this->siteUrl, '/').$this->inboxPath($locale),
                        'cta' => $this->translator->trans('messages.email_cta', [], null, $locale),
                        'why' => $this->translator->trans('messages.email_why', [], null, $locale),
                        'locale' => $locale,
                    ])
            );
        } catch (TransportExceptionInterface $e) {
            // The dashboard still has it. Losing the mail is a degraded
            // notification, not a lost message.
            $this->logger->error('Could not email a dashboard message.', [
                'message_id' => $id,
                'kind' => $message->getKind()->value,
                'transport_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * `/messages` in the recipient's locale.
     *
     * Built by hand rather than through the router because this runs on
     * terminate, where the router's request context is whatever the last
     * request left behind — and in a console command there is no context at
     * all. The prefix map is the same constant the routes are generated from.
     */
    private function inboxPath(string $locale): string
    {
        return (\App\Routing\LocalePrefix::PATHS[$locale] ?? '').'/messages';
    }
}
