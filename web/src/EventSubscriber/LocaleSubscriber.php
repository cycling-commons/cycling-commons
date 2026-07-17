<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the request locale, in order of precedence:
 *   1. a routed `_locale` (set by a path-prefixed localized route),
 *   2. the session `_locale` (set by the language switcher, settings save, or
 *      login-seeding from the user's saved preference),
 *   3. the browser's Accept-Language, constrained to the enabled locales,
 *   4. the site default.
 *
 * Anonymous first-time visitors are handled without forcing a session: we only
 * read the session when the request already carries one (hasPreviousSession).
 *
 * Runs at priority 20, before Symfony's own LocaleListener (16) and after the
 * router (32), so the locale is settled for the rest of the request.
 *
 * @api Auto-registered event subscriber.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // A localized route may carry _locale in the URL. Honour it and remember
        // the choice so it sticks once the user leaves prefixed paths.
        $routed = $request->attributes->get('_locale');
        if (\is_string($routed) && \in_array($routed, $this->enabledLocales, true)) {
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $routed);
            }

            return; // Symfony's LocaleListener sets the locale from the attribute
        }

        // Respect a previously stored choice without starting a session for
        // brand-new anonymous visitors.
        if ($request->hasPreviousSession()) {
            $stored = $request->getSession()->get('_locale');
            if (\is_string($stored) && \in_array($stored, $this->enabledLocales, true)) {
                $request->setLocale($stored);

                return;
            }
        }

        $request->setLocale($request->getPreferredLanguage($this->enabledLocales) ?: $this->defaultLocale);
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onKernelRequest', 20]]];
    }
}
