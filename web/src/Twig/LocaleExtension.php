<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the current page re-generated in every enabled locale. Used by the
 * nav language switcher (as the post-switch redirect target) and by the
 * `<link rel="alternate" hreflang>` tags in the document head.
 *
 * The URL for each locale is produced by regenerating the *current* route with
 * the router context temporarily pointed at that locale, so localized routes
 * yield their prefixed path (`/fr/regions`) while non-localized routes (e.g.
 * the map) yield the same clean path for every locale.
 *
 * @api Auto-registered Twig extension.
 */
final class LocaleExtension extends AbstractExtension
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('locale_alternates', $this->localeAlternates(...)),
        ];
    }

    /**
     * `urls` maps each enabled locale to the current page's path in that locale.
     * `localized` is true only when those paths actually differ (i.e. the current
     * route has per-locale variants), so callers can skip emitting hreflang for
     * English-only pages such as the map.
     *
     * @return array{localized: bool, urls: array<string, string>}
     */
    public function localeAlternates(): array
    {
        $empty = ['localized' => false, 'urls' => []];

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return $empty;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || '' === $route) {
            return $empty;
        }

        /** @var array<string, mixed> $params */
        $params = $request->attributes->get('_route_params', []);
        // The target locale is driven through the router context, not the
        // parameters. Passing `_locale` as a param would append `?_locale=…`
        // to non-localized routes.
        unset($params['_locale']);

        // The QUERY STRING is not part of the route, so regenerating from
        // _route/_route_params alone silently drops it — and on the map that is
        // the whole state: switching language on
        // `/map?scope=region:niedersachsen` landed the rider on a bare `/map`,
        // i.e. back on the default scope, looking at a different region
        // (reported 2026-07-27 as "the map does not change locale": the labels
        // were translating correctly all along, the rider had just been moved
        // somewhere else). `?feature=`, `?route=` and `?pending=` deep links
        // were lost the same way. `_locale` is dropped because the target
        // locale rides the router context, not the query.
        $query = $request->query->all();
        unset($query['_locale']);
        $suffix = [] === $query ? '' : '?'.http_build_query($query);

        $context = $this->router->getContext();
        $previous = $context->getParameter('_locale');
        $urls = [];

        try {
            foreach ($this->enabledLocales as $locale) {
                $context->setParameter('_locale', $locale);
                try {
                    $urls[$locale] = $this->router->generate($route, $params).$suffix;
                } catch (\Throwable) {
                    // Route not generatable in this locale (e.g. a required
                    // parameter is absent). Omit it rather than fail the page.
                }
            }
        } finally {
            $context->setParameter('_locale', $previous);
        }

        return [
            // Compare the PATHS, not the paths-plus-query: an identical query on
            // every locale must not make an English-only page look localized and
            // start emitting hreflang.
            'localized' => \count(array_unique(array_map(
                static fn (string $u): string => strtok($u, '?') ?: $u,
                $urls,
            ))) > 1,
            'urls' => $urls,
        ];
    }
}
