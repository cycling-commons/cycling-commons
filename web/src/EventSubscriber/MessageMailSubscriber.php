<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Messaging\MessageMailer;
use App\Messaging\MessageOutbox;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends the queued message emails once the work is finished.
 *
 * `terminate` is the point where two things are finally true at once: the
 * moderation transaction has committed (or has not), and the response is
 * already on its way to the browser. Mailing anywhere earlier would either
 * announce a decision that can still roll back, or make a curator wait on an
 * SMTP round trip before their desk responds.
 *
 * Both terminate events are handled, because messages are created from both
 * sides: `kernel.terminate` for a curator clicking a decision, and
 * `console.terminate` for anything a command sends.
 *
 * There is no Messenger transport configured, so this IS the delivery
 * mechanism. When one is introduced, the honest change is to have this
 * subscriber dispatch instead of send — the outbox and the re-check stay
 * useful either way (docs/specs/moderation-and-contribution.md §7.8).
 *
 * @api Auto-registered event subscriber.
 */
final readonly class MessageMailSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageOutbox $outbox,
        private MessageMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
        ];
    }

    public function flush(): void
    {
        foreach ($this->outbox->drain() as $message) {
            try {
                $this->mailer->deliver($message);
            } catch (\Throwable $e) {
                /* One undeliverable message must not stop the others. This runs
                   after the response, so nobody is waiting on it and there is
                   nothing to surface an error to — the log is the only place
                   this can be seen. */
                $this->logger->error('Failed to deliver a message email.', [
                    'message_id' => $message->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
