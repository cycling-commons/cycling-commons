<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request locale: routed `_locale`, then session, then Accept-Language, then default.
 *
 * @api
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

        $routed = $request->attributes->get('_locale');
        if (\is_string($routed) && \in_array($routed, $this->enabledLocales, true)) {
            // `hasPreviousSession()`, NOT `hasSession()`. `hasSession()` is true
            // on every request the moment sessions are enabled, so writing here
            // started and filled a session for every anonymous reader, which
            // sent PHPSESSID to everyone and put a Redis entry behind every
            // crawler hit. The read below already used `hasPreviousSession()`;
            // this only makes the write agree with it.
            //
            // Nothing is lost for anyone who has a session: the locale is still
            // remembered for them. Someone with no session at all now falls
            // through to Accept-Language on the unprefixed routes (`/verify`,
            // `/2fa`, `/admin`), which is where the stored value was ever read
            // -- and they had nothing stored to read anyway.
            if ($request->hasPreviousSession()) {
                $request->getSession()->set('_locale', $routed);
            }

            return;
        }

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
