<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Messaging\MessageService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks a message or a room post read when its reader arrives at the thing it
 * is about through the item's own link.
 *
 * A message's links carry `?msg=<id>` (the map for an approved place or
 * route, the edit form for a needs-info question); a room post's link to its
 * submission carries `?post=<id>`. Following one of those is opening the
 * item, so it comes off its count, one step. The id is checked against the
 * signed-in reader: a message not addressed to them, or a post they may not
 * see, is left alone. Only on the named targets, so no other page marks
 * anything by being loaded.
 *
 * @see docs/specs/moderation-and-contribution.md §7.5a
 * @see docs/specs/moderation-and-contribution.md §13.7
 *
 * @api
 */
final readonly class OpenedByLinkSubscriber implements EventSubscriberInterface
{
    /** Routes a message links to, by canonical name. */
    private const array MESSAGE_TARGETS = ['map', 'improve'];

    /** Routes a room post links to, by canonical name. */
    private const array POST_TARGETS = ['moderate_submissions', 'moderate_history'];

    public function __construct(
        private Security $security,
        private MessageService $messages,
        private CuratorRoom $room,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('GET')) {
            return;
        }
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        $param = match (true) {
            \in_array($route, self::MESSAGE_TARGETS, true) => 'msg',
            \in_array($route, self::POST_TARGETS, true) => 'post',
            default => null,
        };
        // Route and parameter first: the signed-in user is read only when a
        // link named an item, so no other request touches the session here.
        $id = null !== $param ? self::id($request->query->all()[$param] ?? null) : null;
        if (null === $id) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            return;
        }

        if ('msg' === $param) {
            $this->messages->markOpened((int) $user->getId(), $id);
        } elseif ($this->security->isGranted('ROLE_CURATOR')) {
            $this->room->markRead((int) $user->getId(), $id);
        }
    }

    /** A positive integer id, or null for anything else (an array, words, zero). */
    private static function id(mixed $raw): ?int
    {
        if (!\is_string($raw) || 1 !== preg_match('/^[1-9]\d{0,17}$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }
}
