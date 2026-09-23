<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Mail;

use App\EventSubscriber\MailRehearsalRedirectSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** The production rehearsal runs on staging's rows; no mail may reach them. */
final class MailRehearsalRedirectSubscriberTest extends TestCase
{
    private function event(): MessageEvent
    {
        $email = (new Email())->from('noreply@cyclingcommons.test')->to('rider@example.test')->text('x');

        return new MessageEvent($email, new Envelope(new Address('noreply@cyclingcommons.test'), [new Address('rider@example.test')]), 'smtp');
    }

    public function testSetRedirectsTheEnvelope(): void
    {
        $event = $this->event();
        (new MailRehearsalRedirectSubscriber(['owner@example.test']))->onMessage($event);

        self::assertSame(['owner@example.test'], array_map(static fn (Address $a): string => $a->getAddress(), $event->getEnvelope()->getRecipients()));
    }

    public function testEmptyLeavesTheEnvelopeAlone(): void
    {
        foreach ([[], [''], [null]] as $off) {
            $event = $this->event();
            (new MailRehearsalRedirectSubscriber($off))->onMessage($event);

            self::assertSame('rider@example.test', $event->getEnvelope()->getRecipients()[0]->getAddress());
        }
    }

    public function testRunsAfterSymfonysEnvelopeListener(): void
    {
        self::assertLessThan(-255, MailRehearsalRedirectSubscriber::getSubscribedEvents()[MessageEvent::class][1]);
    }
}
