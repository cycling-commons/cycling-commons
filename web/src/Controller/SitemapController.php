<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Blog\BlogLocales;
use App\Blog\BlogRepository;
use App\Catalog\RegionRegistryProvider;
use App\Routing\ActiveLocales;
use App\Service\BuildVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * `robots.txt`, `sitemap.xml`, `security.txt` and `humans.txt` from the live
 * router.
 *
 * The four machine-readable files at the root of the site. They share a
 * controller because they share a property: none of them is a page, all of them
 * must agree with what the router actually serves, and each one rots quietly if
 * it is a static file somebody has to remember to edit. Two of them carry a
 * date, and neither date is written by hand: `security.txt` computes an
 * `Expires` that is always valid, and `humans.txt` takes its "last update" from
 * the build.
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
        // Unlike the bug form, the report guide is a page a reader searches
        // for ("how do I report a photo"), so it belongs here.
        ['report_guide', 'monthly'],
        ['known_issues', 'daily'],
        // Yearly is optimistic for this one: it changes whenever a barrier is
        // fixed or found, which is the point of it.
        ['accessibility', 'monthly'],
        ['roles', 'monthly'],
        // Both change with every release, which is more often than the rest of
        // this list, and both are how somebody finds out the project is alive.
        ['roadmap', 'weekly'],
        ['changelog', 'weekly'],
        // The machine-readable contract's rendered reference. English-only
        // content under five localised paths, which is why it is one entry
        // with alternates like any other page: the URL is per language even
        // where the words are not.
        ['developers_api', 'monthly'],
        // The blog index. Its posts are listed separately, below, because a
        // post is reachable in some languages and not others.
        ['blog', 'weekly'],
        // What the pins and colours on the map mean. A reader searches for
        // this ("what does the red dashed line mean"), and it is the one page
        // that explains the map without loading it.
        ['map_key', 'monthly'],
        ['privacy', 'yearly'],
        ['terms', 'yearly'],
    ];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly RegionRegistryProvider $regions,
        private readonly ActiveLocales $activeLocales,
        private readonly BlogRepository $blog,
        private readonly BuildVersion $build,
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

    /**
     * The credits file, with a date that cannot go stale.
     *
     * The old static `atlas/demo/humans.txt` carried `Last update: 2026/06/17`,
     * a line somebody had to remember to edit and nobody did, on a file the
     * application never served anyway. A hand-kept date is worse than no date:
     * it does not say when the site last changed, it says when a person last
     * thought about this file, and a reader cannot tell the two apart.
     *
     * So it comes from the build instead. `BuildVersion` reads the `REVISION`
     * the deploy writes, or git in a working copy, which means the line moves
     * on every deploy on its own and is never a promise anyone has to keep by
     * hand. When the build cannot name a date the line is left out rather than
     * guessed, because an absent date is honest and a made-up one is not.
     */
    #[Route('/humans.txt', name: 'humans_txt', methods: ['GET'])]
    public function humansTxt(): Response
    {
        $url = fn (string $route): string => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $build = $this->build->stamp();

        $lines = [
            '/* TEAM */',
            '  Maintained by: BikeCoders - https://bikecoders.life',
            '  Contact: development [at] cyclingcommons.org',
            '  The Commons is stewarded openly, on a path to an independent foundation.',
            '  See: https://wiki.cyclingcommons.org/governance/',
            '',
            '/* THANKS */',
            '  OpenStreetMap and its contributors - the ground we build on',
            '  Every rider who maps a climb, water point, viewpoint, stay or hazard',
            '  The open-data and open-hospitality communities we link and interoperate with',
            '',
            '/* DATA & LICENCE */',
            '  Data:  Open Database License (ODbL 1.0)',
            '  Media: Commons Media License (CC BY-SA 4.0)',
            '  The map belongs to everyone - places, never people: the Commons dataset',
            '  holds no personal data. An account, and our analytics and server logs,',
            '  are a separate matter: '.$url('privacy'),
            '  Licences: '.$url('licenses'),
            '',
            '/* SITE */',
        ];

        if ('' !== $build['date']) {
            $lines[] = '  Last update: '.$build['date'];
        }
        $lines[] = '  Build: '.$build['number'];

        $lines = [...$lines,
            '  Components: MapLibre GL, PMTiles, OpenFreeMap',
            '  Wiki: MkDocs Material - https://wiki.cyclingcommons.org',
            '  Source: '.$build['url'],
            '',
            '                  .',
            '                 /|\\',
            '                / | \\      one open atlas',
            '               /__|__\\     for every kind of cycling',
        ];

        $response = new Response(implode("\n", $lines)."\n");
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
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
            '/admin', '/moderate', '/account',
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
        // What this deployment serves, not every prefix compiled in: a
        // sitemap entry for a language that answers 404 is an error
        // report waiting in a search console (dev-environment.md §7 i18n).
        $locales = $this->activeLocales->all();
        $urls = [];

        foreach (self::PAGES as [$name, $freq]) {
            $urls[] = $this->entry($name, [], $freq, 'home' === $name ? '1.0' : '0.7', $locales);
        }

        // Operational regions only — L2 outlines have no page.
        foreach ($this->regions->all() as $region) {
            $urls[] = $this->entry('region_detail', ['slug' => $region['slug']], 'weekly', '0.6', $locales);
        }

        // One entry per live post, in the languages that actually serve it.
        // The blog is written in two languages and read in five, and the
        // fallback runs one way only (BlogController::post): a reader in any
        // language gets an English post, but only a Dutch reader gets a
        // Dutch-only one. Listing a Dutch post under /blog/ would therefore
        // put a 404 in the sitemap, which is the error this file exists to
        // avoid.
        foreach ($this->blog->liveSlugs() as $post) {
            $reachable = BlogLocales::FALLBACK === $post['locale']
                ? $locales
                : array_values(array_intersect($locales, [$post['locale']]));
            // A post in a language this deployment does not serve has no
            // address a reader can reach, so it has no sitemap entry either.
            if ([] === $reachable) {
                continue;
            }
            $urls[] = $this->entry('blog_post', ['slug' => $post['slug']], 'monthly', '0.5', $reachable);
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
