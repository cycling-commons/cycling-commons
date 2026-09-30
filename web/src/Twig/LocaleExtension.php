<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Routing\ActiveLocales;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Current page regenerated in every enabled locale (language switcher + hreflang).
 *
 * @api
 */
final class LocaleExtension extends AbstractExtension
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        // What this deployment serves, not what it was built with: a
        // language nobody may reach has no switcher entry and no hreflang
        // line pointing search engines at a 404 (dev-environment.md §7 i18n).
        private readonly ActiveLocales $activeLocales,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('locale_alternates', $this->localeAlternates(...)),
            new TwigFunction('active_locales', $this->activeLocales->all(...)),
        ];
    }

    /**
     * This page in every enabled locale, in two forms.
     *
     * `urls` keeps the query string, for the language switcher: `/map?scope=`
     * (and feature/route/pending) is not in the route, and switching language
     * must land on the same view. `paths` has no query string at all, for the
     * hreflang block, which names the same clean URL as the canonical so a
     * shared link's tracking parameters (`?utm_source=`) never reach a search
     * engine as a page of their own. `localized` is true only when the paths
     * differ.
     *
     * @return array{localized: bool, urls: array<string, string>, paths: array<string, string>}
     */
    public function localeAlternates(): array
    {
        $empty = ['localized' => false, 'urls' => [], 'paths' => []];

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
        unset($params['_locale']);

        $query = $request->query->all();
        unset($query['_locale']);
        $suffix = [] === $query ? '' : '?'.http_build_query($query);

        $context = $this->router->getContext();
        $previous = $context->getParameter('_locale');
        $paths = [];

        try {
            foreach ($this->activeLocales->all() as $locale) {
                $context->setParameter('_locale', $locale);
                try {
                    // A route default that is not a path variable comes back
                    // as a query string; the path is everything before it.
                    $paths[$locale] = explode('?', $this->router->generate($route, $params), 2)[0];
                } catch (\Throwable) {
                }
            }
        } finally {
            $context->setParameter('_locale', $previous);
        }

        return [
            'localized' => \count(array_unique($paths)) > 1,
            'urls' => array_map(static fn (string $path): string => $path.$suffix, $paths),
            'paths' => $paths,
        ];
    }
}
