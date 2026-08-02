<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * The one mail that goes out when a curator escalates something as suspected
 * illegal content (docs/specs/photo-uploads.md §6d) — a photo or a
 * submission's words.
 *
 * Shared by both paths on purpose: an escalation must reach a human the same
 * way whatever kind of content it was, and two copies of this would drift.
 *
 * Three rules the body obeys:
 *
 * - **Unthrottled.** Unlike the circuit-breaker alert, this is one deliberate
 *   act by a trusted person, not a flood. Every one gets a mail.
 * - **It carries no content.** Not the image, not the submission's text — only
 *   the curator's own description, a reference, and where to look. Suspected
 *   illegal material must not be copied into a mailbox on its way to being
 *   handled.
 * - **A failure is CRITICAL, never fatal.** An escalation nobody hears about is
 *   the one failure here that must be impossible to miss in the logs, but a
 *   dead transport must not roll back the hold that has already been applied.
 *
 * @api Called by MediaEscalationService and ModerationService.
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
