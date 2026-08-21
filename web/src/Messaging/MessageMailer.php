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
 * Email a dashboard message to its recipient.
 *
 * @see docs/specs/moderation-and-contribution.md §7.8
 *
 * @api
 */
final readonly class MessageMailer
{
    /** Kinds that are not emailed (curator desk already shows RiderReply). */
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

        // Re-read: the creating transaction may have rolled back after queueing.
        // Re-read: the creating transaction may have rolled back after queueing.
        $id = $message->getId();
        if (null === $id || null === $this->em->find(UserMessage::class, $id)) {
            return;
        }

        $user = $this->em->find(User::class, $message->getUserId());
        if (!$user instanceof User) {
            return;
        }
        $email = $user->getEmail();
        if ('' === $email) {
            return;
        }

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
                        'note' => $message->getBodyText(),
                        'reference' => $message->getRefLabel(),
                        'messages_url' => rtrim($this->siteUrl, '/').$this->inboxPath($locale),
                        'cta' => $this->translator->trans('messages.email_cta', [], null, $locale),
                        'why' => $this->translator->trans('messages.email_why', [], null, $locale),
                        'locale' => $locale,
                    ])
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not email a dashboard message.', [
                'message_id' => $id,
                'kind' => $message->getKind()->value,
                'transport_error' => $e->getMessage(),
            ]);
        }
    }

    /** `/messages` in the recipient's locale (no router: this runs on terminate). */
    private function inboxPath(string $locale): string
    {
        return (\App\Routing\LocalePrefix::PATHS[$locale] ?? '').'/messages';
    }
}
