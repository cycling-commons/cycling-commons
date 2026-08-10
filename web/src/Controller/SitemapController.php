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
 * `robots.txt` and `sitemap.xml`.
 *
 * An open atlas that search engines cannot enumerate is an atlas nobody finds,
 * and the Commons has one page per region that is worth finding on its own.
 *
 * Both are served from the controller rather than dropped in `public/` so they
 * cannot drift: the sitemap is generated from the router and the live region
 * registry, and robots.txt points at whatever host is serving it. A static file
 * would have to be edited every time a country onboards, and would name a
 * hostname that is wrong in three of the four environments.
 *
 * What is deliberately NOT listed: anything behind a login (profile, settings,
 * messages, every moderation desk), the contribution wizards, and `/map`. The
 * map is one URL whose entire content is query state — listing it adds nothing,
 * and listing its states would be thousands of near-identical pages.
 *
 * @api Instantiated by Symfony's router.
 */
final class SitemapController extends AbstractController
{
    /**
     * Public, indexable, locale-varying routes. Ordered roughly by importance,
     * which is also the order a reader would meet them.
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

        /* Disallowed paths are the ones with nothing to index and a cost to
           crawling: signed-in surfaces, the contribution wizards (which are
           multi-step forms), and the JSON the map fetches. This is a crawl
           budget hint, NOT an access control — everything genuinely private is
           behind the firewall, and a robots.txt that were the only thing
           standing between a crawler and a moderation desk would be a bug. */
        $disallow = [
            '/admin', '/moderate', '/profile', '/settings', '/messages',
            '/login', '/register', '/reset-password', '/2fa', '/i18n/',
            '/contribute', '/add-climb', '/improve', '/propose-route',
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

        /* One entry per region page. The registry already applies
           OperationalRegions::predicate(), so the L2 country outlines are
           filtered out at the query — they are infrastructure rows with no page
           of their own (tools/divisions/README.md, "Operational vs
           infrastructure rows"). */
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
     * One `<url>`, with an `xhtml:link alternate` per locale.
     *
     * The alternates are the whole reason this is generated rather than
     * written: five locales × every page is the kind of list that is correct
     * on the day it is committed and wrong by the next one.
     *
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
                    continue;   // not generatable in this locale — omit it, do not fail the sitemap
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
