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
 * `robots.txt`, `sitemap.xml` and `security.txt` from the live router.
 *
 * The three machine-readable files at the root of the site. They share a
 * controller because they share a property: none of them is a page, all of them
 * must agree with what the router actually serves, and each one rots quietly if
 * it is a static file somebody has to remember to edit.
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
        // The front door and the two support pages. `contact` carries the
        // legal identity block, so it is one of the pages a reader most needs
        // to be able to find from a search (contact-and-support.md §2).
        // `report-bug` is deliberately NOT here: it is a form, reached from
        // the button on every page, and has nothing for a search engine.
        ['contact', 'monthly'],
        ['known_issues', 'daily'],
        // Yearly is optimistic for this one: it changes whenever a barrier is
        // fixed or found, which is the point of it.
        ['accessibility', 'monthly'],
        ['privacy', 'yearly'],
        ['terms', 'yearly'],
    ];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly RegionRegistryProvider $regions,
    ) {
    }

    /**
     * RFC 9116 security contact, with an expiry that cannot rot.
     *
     * `SECURITY.md` has pointed at this URL since it was written, and until now
     * this URL answered 404: the only `security.txt` was the one beside the old
     * static atlas, whose `Canonical:` line named an address the application did
     * not serve. A canonical URL that 404s is worse than no file, because a
     * researcher who checks it concludes there is nowhere to report.
     *
     * `Expires` is REQUIRED by RFC 9116, must be under a year out, and a file
     * past it is to be treated as invalid. A hand-written date is therefore a
     * time bomb: the day it passes, the file stops counting and nobody notices,
     * which is the usual way security.txt fails. This one is computed as the
     * first of the month nine months from now. Always valid, never a year out,
     * and stable for a whole month at a time so it still caches and still
     * matches byte for byte between requests.
     */
    #[Route('/.well-known/security.txt', name: 'security_txt', methods: ['GET'])]
    public function securityTxt(): Response
    {
        $expires = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC')))
            ->setTime(0, 0)
            ->modify('+9 months');

        $lines = [
            '# Cycling Commons security contact (RFC 9116).',
            '# The open Commons is non-personal map data, but the platform holds account',
            '# data (emails, salted password hashes) and security logs (IP addresses), so',
            '# vulnerabilities matter to us. Report privately first; we will respond.',
            '',
            'Contact: mailto:development@cyclingcommons.org',
            'Contact: '.$this->generateUrl('contact', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'Expires: '.$expires->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: en, nl',
            'Canonical: '.$this->generateUrl('security_txt', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'Policy: https://github.com/cycling-commons/cycling-commons/blob/main/SECURITY.md',
        ];

        $response = new Response(implode("\n", $lines)."\n");
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        // A day is plenty: the body only changes once a month, and a researcher
        // reading a cached copy still gets a valid, unexpired file.
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
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
