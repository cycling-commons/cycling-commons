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
 * Current page regenerated in every enabled locale (language switcher + hreflang).
 *
 * @api
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
     * `urls` maps each enabled locale to this page. `localized` is true only when paths differ.
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
        unset($params['_locale']);

        // Keep the query string: `/map?scope=` (and feature/route/pending) is not in the route.
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
                }
            }
        } finally {
            $context->setParameter('_locale', $previous);
        }

        return [
            'localized' => \count(array_unique(array_map(
                static fn (string $u): string => strtok($u, '?') ?: $u,
                $urls,
            ))) > 1,
            'urls' => $urls,
        ];
    }
}
