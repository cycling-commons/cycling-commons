<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Translation\MarkerCodec;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * The second of the two nets that stop a translate-mode mark leaving the
 * server anywhere except inside an HTML page (translations.md §4.1).
 *
 * `TranslateMode::decide()` refuses every non-GET request (a curator's
 * needs-info decision, and every other action that sends mail, is a POST),
 * so nothing on this site can currently render a `TemplatedEmail` body with
 * the mode on: this net is defence in depth, not a currently reachable path.
 * It exists so a future code path (a queued digest rendered outside a
 * request, a different render context) cannot reopen the hole.
 *
 * @api
 */
final readonly class MarkStripMailSubscriber implements EventSubscriberInterface
{
    /** @return array<string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        // Symfony renders the TemplatedEmail body in MessageListener at the
        // implicit default priority (0), signs it at -128
        // (DkimSignedMessageListener / SmimeSignedMessageListener), and logs
        // the message for test assertions at -255 (MessageLoggerListener /
        // EnvelopeListener) (vendor/symfony/mailer/EventListener/*.php,
        // verified 2026-08-31). -100 runs after the render and before both
        // the signature and the log.
        return [MessageEvent::class => ['onMessage', -100]];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        $html = $message->getHtmlBody();
        if (\is_string($html)) {
            $message->html(MarkerCodec::stripAllForms($html));
        }

        $text = $message->getTextBody();
        if (\is_string($text)) {
            $message->text(MarkerCodec::stripAllForms($text));
        }
    }
}
