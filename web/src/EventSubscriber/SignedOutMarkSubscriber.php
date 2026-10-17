<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Marks the way home after signing out with `#signed-out`, so the browser
 * forgets the ride being planned there: the draft and its undo and redo
 * steps (assets/js/signed-out.js). A fragment never reaches a server, so the
 * mark tells our side nothing and lands in no log.
 *
 * Runs after the firewall's own logout listener has set the redirect home.
 *
 * @see docs/specs/route-planner.md section 6.0
 *
 * @api
 */
final readonly class SignedOutMarkSubscriber implements EventSubscriberInterface
{
    public const string MARK = 'signed-out';

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', -64]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response instanceof RedirectResponse) {
            return;
        }
        $target = $response->getTargetUrl();
        $hash = strpos($target, '#');
        $response->setTargetUrl((false === $hash ? $target : substr($target, 0, $hash)).'#'.self::MARK);
    }
}
