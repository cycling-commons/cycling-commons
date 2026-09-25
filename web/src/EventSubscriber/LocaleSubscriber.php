<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Routing\ActiveLocales;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $routed = $request->attributes->get('_locale');
        if (\is_string($routed) && !$this->activeLocales->isActive($routed)) {
            // The route exists, because the prefix is compiled in for every
            // built catalogue, but this deployment does not serve that
            // language. A reader following an old link or a search result
            // lands on the same page in the default locale. 302, not 301:
            // the language comes back once its catalogue is finished.
            $event->setResponse(new RedirectResponse($this->defaultLocaleUrl($request), 302));

            return;
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

    /**
     * The same route and parameters in the default locale, query string kept; the home page when the route cannot be rebuilt.
     */
    private function defaultLocaleUrl(Request $request): string
    {
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        $params = $request->attributes->get('_route_params');
        if (!\is_string($route) || !\is_array($params)) {
            return '/';
        }
        $params['_locale'] = $this->defaultLocale;

        try {
            $url = $this->urlGenerator->generate($route, $params);
        } catch (RoutingException) {
            return '/';
        }

        $query = $request->getQueryString();

        return null === $query ? $url : $url.'?'.$query;
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onKernelRequest', 20]]];
    }
}
