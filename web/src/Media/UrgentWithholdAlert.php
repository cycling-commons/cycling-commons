<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Ops alert when the auto-withhold breaker opens. Throttled; never fails the request.
 *
 * @see docs/specs/photo-uploads.md §6c
 *
 * @api
 */
final class UrgentWithholdAlert
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RateLimiterFactory $mediaUrgentAlertLimiter,
        private readonly LoggerInterface $logger,
        private readonly AlertRecipients $recipients,
        #[Autowire('%env(APP_SITE_URL)%')]
        private readonly string $siteUrl,
    ) {
    }

    public function breakerOpened(): void
    {
        $to = $this->recipients->all();
        if ([] === $to) {
            return;
        }
        if (!$this->mediaUrgentAlertLimiter->create('global')->consume()->isAccepted()) {
            return;   // already told them this hour
        }

        $email = (new Email())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(...$to)
            ->subject('[Cycling Commons] Photo auto-withhold circuit breaker is OPEN')
            ->text(<<<TXT
                The site-wide budget for automatically withholding photos on an
                anonymous "intimate imagery / a child is depicted" report has
                run out (docs/specs/photo-uploads.md §6c).

                What this means right now:

                  * Urgent photo reports are STILL being filed and still reach
                    the moderation desk. Nothing is being lost.
                  * They are NO LONGER taking photos down by themselves. From
                    here on a curator decides every removal.

                Genuine reports of this kind are rare, so a burst large enough
                to exhaust the budget is usually either an attack on this route
                or something happening at scale. Both want a person now.

                Moderation desk:  {$this->siteUrl}/moderate
                Restore withheld: {$this->siteUrl}/admin/withheld-photos

                The restore page lists every photo an anonymous report has
                taken down and puts them all back in one action, without
                closing the door on a future genuine report of the same kind.
                TXT);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Mail must not 500 a rights-exercise route; the CRITICAL log is the record.
            $this->logger->error('Could not send the auto-withhold breaker alert.', ['exception' => $e]);
        }
    }
}
