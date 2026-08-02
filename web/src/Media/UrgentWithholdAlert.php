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
 * Tells a human that the auto-withhold circuit breaker has opened
 * (docs/specs/photo-uploads.md §6c).
 *
 * Two properties matter more than the wording:
 *
 * 1. **It is throttled.** The breaker opens under a flood, and the flood keeps
 *    arriving afterwards — a mail per report would be thousands of messages,
 *    which is a second denial of service and aimed at the one person who has
 *    to read them. One message per hour, keyed globally, is enough to say
 *    "this is happening" and to keep saying it while it continues.
 * 2. **It never breaks the request.** A mail transport that is down, slow or
 *    misconfigured must not turn into a 500 on a rights-exercise route. A
 *    failure is logged and swallowed; the CRITICAL log line from the breaker
 *    is the durable record either way.
 *
 * Deliberately a plain text Email rather than a TemplatedEmail: this is an ops
 * alert to one operator address, not user-facing copy, so it has no business
 * in the translation catalogs.
 *
 * @api Called by UrgentWithholdBreaker when the budget runs out.
 */
final class UrgentWithholdAlert
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RateLimiterFactory $mediaUrgentAlertLimiter,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(SECURITY_ALERT_EMAIL)%')]
        private readonly string $alertAddress,
        #[Autowire('%env(APP_SITE_URL)%')]
        private readonly string $siteUrl,
    ) {
    }

    public function breakerOpened(): void
    {
        if ('' === $this->alertAddress) {
            return;
        }
        if (!$this->mediaUrgentAlertLimiter->create('global')->consume()->isAccepted()) {
            return;   // already told them this hour
        }

        $email = (new Email())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(new Address($this->alertAddress))
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
            // Swallowed on purpose: see the class docblock. The alert is a
            // courtesy on top of the CRITICAL log, never a precondition for
            // handling the report.
            $this->logger->error('Could not send the auto-withhold breaker alert.', ['exception' => $e]);
        }
    }
}
