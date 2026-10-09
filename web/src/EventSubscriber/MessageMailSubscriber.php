<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Messaging\MessageMailer;
use App\Messaging\MessageOutbox;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Send queued message emails on kernel/console terminate, and on the
 * messenger worker after each message it handled or failed: the worker runs
 * for an hour, and a decision made in a handler (a refused upload) must not
 * wait for it to exit.
 *
 * @see docs/specs/moderation-and-contribution.md §7.8
 *
 * @api
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
            WorkerMessageHandledEvent::class => 'flush',
            WorkerMessageFailedEvent::class => 'flush',
        ];
    }

    public function flush(): void
    {
        foreach ($this->outbox->drain() as $message) {
            try {
                $this->mailer->deliver($message);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to deliver a message email.', [
                    'message_id' => $message->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
