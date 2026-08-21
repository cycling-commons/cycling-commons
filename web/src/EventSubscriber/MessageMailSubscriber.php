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
 * Send queued message emails on kernel/console terminate.
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
