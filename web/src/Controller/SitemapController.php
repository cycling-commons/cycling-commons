<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\RegionRegistryProvider;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * `robots.txt` and `sitemap.xml` from the live router and region registry.
 *
 * @api
 */
final class SitemapController extends AbstractController
{
    /**
     * Public, indexable, locale-varying routes.
     *
     * @var list<array{0: string, 1: string}> [route name, change frequency]
     */
    private const array PAGES = [
        ['home', 'weekly'],
        ['regions', 'daily'],
        ['coverage', 'daily'],
        ['about', 'monthly'],
        ['join', 'monthly'],
        ['scout', 'monthly'],
        ['contributors', 'weekly'],
        ['developers', 'monthly'],
        ['licenses', 'yearly'],
        ['credits', 'monthly'],
        ['pages', 'monthly'],
        ['privacy', 'yearly'],
        ['terms', 'yearly'],
    ];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly RegionRegistryProvider $regions,
    ) {
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET'])]
    public function robots(): Response
    {
        $sitemap = $this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // Crawl hint, not ACL — private surfaces sit behind the firewall.
        $disallow = [
            '/admin', '/moderate', '/profile', '/settings', '/messages',
            '/login', '/register', '/reset-password', '/2fa', '/i18n/',
            '/contribute', '/improve', '/propose-route',
            '/map/', '/api/', '/photo/',
        ];

        $lines = ['User-agent: *'];
        foreach ($disallow as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$sitemap;

        return new Response(
            implode("\n", $lines)."\n",
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $locales = array_keys(LocalePrefix::PATHS);
        $urls = [];

        foreach (self::PAGES as [$name, $freq]) {
            $urls[] = $this->entry($name, [], $freq, 'home' === $name ? '1.0' : '0.7', $locales);
        }

        // Operational regions only — L2 outlines have no page.
        foreach ($this->regions->all() as $region) {
            $urls[] = $this->entry('region_detail', ['slug' => $region['slug']], 'weekly', '0.6', $locales);
        }

        $xml = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">',
            ...$urls,
            '</urlset>',
        ])."\n";

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param array<string, string> $params
     * @param list<string>          $locales
     */
    private function entry(string $route, array $params, string $freq, string $priority, array $locales): string
    {
        $context = $this->router->getContext();
        $previous = $context->getParameter('_locale');
        $alternates = [];
        $canonical = null;

        try {
            foreach ($locales as $locale) {
                $context->setParameter('_locale', $locale);
                try {
                    $url = $this->router->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
                } catch (\Throwable) {
                    continue; // omit locales that cannot generate this route
                }
                $canonical ??= $url;
                $alternates[] = sprintf(
                    '    <xhtml:link rel="alternate" hreflang="%s" href="%s"/>',
                    $locale,
                    htmlspecialchars($url, \ENT_XML1),
                );
            }
        } finally {
            $context->setParameter('_locale', $previous);
        }

        return implode("\n", [
            '  <url>',
            '    <loc>'.htmlspecialchars((string) $canonical, \ENT_XML1).'</loc>',
            ...$alternates,
            '    <changefreq>'.$freq.'</changefreq>',
            '    <priority>'.$priority.'</priority>',
            '  </url>',
        ]);
    }
}
