<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Routing\ActiveLocales;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request locale: routed `_locale`, then session, then Accept-Language, then default.
 *
 * @api
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale,
        // The runtime list, not %kernel.enabled_locales%: a catalogue this
        // build carries is not automatically one this deployment serves
        // (dev-environment.md §7 i18n).
        private readonly ActiveLocales $activeLocales,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $routed = $request->attributes->get('_locale');
        if (\is_string($routed) && !$this->activeLocales->isActive($routed)) {
            // The route exists, because the prefix is compiled in for every
            // built catalogue, but this deployment does not serve that
            // language. Answer exactly as a URL that was never a route: a
            // reader typing /fr/ or following an old link learns nothing
            // about a translation that is not ready to be read.
            throw new NotFoundHttpException(sprintf('Locale "%s" is not served here.', $routed));
        }
        if (\is_string($routed)) {
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
            if (\is_string($stored) && $this->activeLocales->isActive($stored)) {
                $request->setLocale($stored);

                return;
            }
        }

        $request->setLocale($request->getPreferredLanguage($this->activeLocales->all()) ?: $this->defaultLocale);
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onKernelRequest', 20]]];
    }
}
