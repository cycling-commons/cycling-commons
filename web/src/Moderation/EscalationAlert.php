<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Media\AlertRecipients;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Mail when a curator escalates suspected illegal content
 * (docs/specs/photo-uploads.md §6d). Body carries no content. A send
 * failure is CRITICAL, never fatal — the hold must not roll back.
 *
 * @api
 */
final readonly class EscalationAlert
{
    public function __construct(
        private MailerInterface $mailer,
        private AlertRecipients $recipients,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_SITE_URL)%')]
        private string $siteUrl,
    ) {
    }

    /**
     * @param string $kind      'photo' or 'submission' — what the admin is about to look at
     * @param string $reference the uuid or SUB-id an admin can search for
     * @param string $reason    the curator's own words, the only description anyone gets
     * @param string $deskPath  where the held item can be reached
     */
    public function escalated(string $kind, string $reference, string $reason, string $deskPath): void
    {
        $to = $this->recipients->all();
        if ([] === $to) {
            $this->logger->critical('Something was escalated but no alert recipients are configured.', ['reference' => $reference]);

            return;
        }

        $email = (new Email())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(...$to)
            ->priority(Email::PRIORITY_HIGH)
            ->subject(\sprintf('[Cycling Commons] URGENT: a %s has been escalated as suspected illegal content', $kind))
            ->text(<<<TXT
                A curator has escalated a {$kind} as suspected illegal content
                (docs/specs/photo-uploads.md §6d). It is already hidden from the
                public and from the moderation desk, and nothing in the app can
                now delete it.

                What they wrote:

                  {$reason}

                Reference: {$reference}
                Held items: {$this->siteUrl}{$deskPath}

                This message deliberately contains none of the content itself.

                Before doing anything else, read the playbook:
                {$this->siteUrl}/admin/playbook/photo-flood

                Do not delete the material. Where it is the kind that must be
                reported, the record has to survive until it has been — and the
                authority you report to decides when it may go.
                TXT);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->critical('Could not send an escalation alert.', ['reference' => $reference, 'exception' => $e]);
        }
    }
}
