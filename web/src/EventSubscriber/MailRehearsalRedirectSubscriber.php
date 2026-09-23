<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

/**
 * While MAILER_ENVELOPE_RECIPIENTS is set, every message goes only there.
 * Guards the production rehearsal, whose rows were copied from staging.
 *
 * @api
 */
final class MailRehearsalRedirectSubscriber implements EventSubscriberInterface
{
    /** @var list<Address> */
    private readonly array $recipients;

    /** @param array<array-key, string|null> $recipients */
    public function __construct(
        #[Autowire('%env(csv:MAILER_ENVELOPE_RECIPIENTS)%')]
        array $recipients,
    ) {
        $this->recipients = array_values(array_map(
            static fn (string $r): Address => Address::create(trim($r)),
            array_filter($recipients, static fn (?string $r): bool => null !== $r && '' !== trim($r)),
        ));
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        // After Symfony's EnvelopeListener (-255), so nothing re-sets the recipients.
        return [MessageEvent::class => ['onMessage', -256]];
    }

    public function onMessage(MessageEvent $event): void
    {
        if ([] !== $this->recipients) {
            $event->getEnvelope()->setRecipients($this->recipients);
        }
    }
}
