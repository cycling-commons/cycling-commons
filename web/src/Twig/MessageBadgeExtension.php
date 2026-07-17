<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Messaging\MessageService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the current user's unread-message count to every template.
 *
 * The account chip's unread bulb reads it on every page render, including
 * anonymous ones, so the no-user path returns 0 without touching the
 * database.
 *
 * @see docs/specs/moderation-and-contribution.md §7.5
 *
 * @api Auto-registered Twig extension.
 */
final class MessageBadgeExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly MessageService $messages,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_message_count', $this->unreadMessageCount(...)),
        ];
    }

    public function unreadMessageCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        $userId = $user->getId();
        if (null === $userId) {
            return 0;
        }

        return $this->messages->unreadCount($userId);
    }
}
