<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Messaging\MessageService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Unread-message count for templates. Anonymous visitors get 0 without a DB hit.
 *
 * @see docs/specs/moderation-and-contribution.md §7.5
 *
 * @api
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
