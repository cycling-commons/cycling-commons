<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\ContributorWallProvider;
use App\Catalog\CoverageStatsProvider;
use App\Catalog\RegionDirectoryProvider;
use App\Catalog\RegionSilhouette;
use App\Content\ReleaseNotes;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static content pages through the shared Twig layout.
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class PageController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->render('pages/index.html.twig', [
            'page_title' => 'meta.home_title',
            'page_description' => 'meta.home_description',
            'nav_active' => '',
        ]);
    }

    /**
     * What is coming. Deliberately a flat list (owner, 2026-08-28: "for now it
     * must be a very simple list"), read straight out of {@see ReleaseNotes}
     * with no database behind it.
     *
     * It shows only unfinished work. What landed is the changelog, linked from
     * the foot of the page, so no item is described twice.
     *
     * @see docs/specs/roadmap-and-changelog.md
     */
    #[Route(LocalizedPath::ROADMAP, name: 'roadmap')]
    public function roadmap(): Response
    {
        return $this->render('pages/roadmap.html.twig', [
            'page_title' => 'meta.roadmap_title',
            'page_description' => 'meta.roadmap_description',
            'nav_active' => '',
            'statuses' => ReleaseNotes::STATUSES,
            'roadmap' => ReleaseNotes::ROADMAP,
            'latest' => ReleaseNotes::latestRelease(),
        ]);
    }

    /**
     * What shipped, newest first.
     *
     * @see docs/specs/roadmap-and-changelog.md
     */
    #[Route(LocalizedPath::CHANGELOG, name: 'changelog')]
    public function changelog(): Response
    {
        return $this->render('pages/changelog.html.twig', [
            'page_title' => 'meta.changelog_title',
            'page_description' => 'meta.changelog_description',
            'nav_active' => '',
            'releases' => ReleaseNotes::RELEASES,
        ]);
    }

    /**
     * The same list as Atom.
     *
     * Unprefixed and English, unlike every other page here. A feed reader is
     * not a browser: it has no locale to route on, people share the URL, and a
     * per-locale feed would fragment subscribers across five addresses for one
     * project. This is the update channel that needs no account, no email
     * address and no app, which is the whole reason it exists.
     */
    #[Route('/changelog.atom', name: 'changelog_atom')]
    public function changelogAtom(Request $request): Response
    {
        $response = $this->render('pages/changelog.atom.twig', [
            'releases' => ReleaseNotes::RELEASES,
            'site' => $request->getSchemeAndHttpHost(),
        ]);
        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');
        // A feed reader polls; there is nothing personal in it and nothing that
        // changes between visits, so let it be cached like the catalogue is.
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * The accessibility statement.
     *
     * Whether the European Accessibility Act binds this site is genuinely
     * unclear: it lists specific consumer services, a free open map is not
     * obviously one of them, and micro-enterprises providing services are
     * exempt. The page says so rather than implying a duty we may not have.
     *
     * It is published anyway, because the WCAG work is real and because the
     * part the Act actually cares about, a channel for reporting a barrier,
     * costs nothing once the contact form exists.
     *
     * @see docs/specs/contact-and-support.md §4
     */
    #[Route(LocalizedPath::ACCESSIBILITY, name: 'accessibility')]
    public function accessibility(): Response
    {
        return $this->render('pages/accessibility.html.twig', [
            'page_title' => 'meta.accessibility_title',
            'page_description' => 'meta.accessibility_description',
            'nav_active' => '',
        ]);
    }

    /**
     * The map key: every mark the map draws, explained in one place.
     *
     * Marks that are designed but not drawn yet stay on the page too, each
     * carrying a visible "planned" tag, so the page is the one complete
     * reference without ever promising a mark a rider cannot find.
     *
     * @see docs/specs/map-and-search.md §4.7
     */
    #[Route(LocalizedPath::MAP_KEY, name: 'map_key')]
    public function mapKey(): Response
    {
        return $this->render('pages/map_key.html.twig', [
            'page_title' => 'meta.map_key_title',
            'page_description' => 'meta.map_key_description',
            'nav_active' => '',
        ]);
    }

    #[Route(LocalizedPath::ABOUT, name: 'about')]
    public function about(): Response
    {
        return $this->render('pages/about.html.twig', [
            'page_title' => 'meta.about_title',
            'page_description' => 'meta.about_description',
            'nav_active' => 'about',
        ]);
    }

    #[Route(LocalizedPath::REGIONS, name: 'regions')]
    public function regions(Request $request, RegionDirectoryProvider $directory, Connection $db): Response
    {
        $locale = $request->getLocale();
        $codes = $db->fetchFirstColumn('SELECT iso2 FROM world_country ORDER BY iso2');

        // Which of them are already on the Commons. Without this the typeahead
        // offered "request your country" for a country listed further up the
        // same page, and somebody would ask us for something they were looking
        // at (owner, 2026-08-28).
        $directoryList = $directory->directory($locale);
        $onboarded = [];
        foreach ($directoryList as $country) {
            $code = (string) ($country['code'] ?? '');
            if ('' !== $code) {
                $onboarded[strtoupper($code)] = true;
            }
        }

        $allCountries = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            $allCountries[] = [
                'code' => $code,
                'name' => Countries::exists($code) ? Countries::getName($code, $locale) : $code,
                'here' => isset($onboarded[strtoupper($code)]),
            ];
        }
        // Collator (locale rules), not strcoll (byte order).
        $collator = new \Collator($locale);
        usort($allCountries, static fn (array $a, array $b): int => $collator->compare($a['name'], $b['name']));

        return $this->render('pages/regions.html.twig', [
            'page_title' => 'meta.regions_title',
            'page_description' => 'meta.regions_description',
            'nav_active' => 'regions',
            'countries' => $directoryList,
            'all_countries' => $allCountries,
        ]);
    }

    /** The pre-DB showcase URL; permanent because the old path was linked externally. */
    #[Route('/region', name: 'region')]
    public function region(): Response
    {
        return $this->redirectToRoute('region_detail', ['slug' => 'wallonia'], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(LocalizedPath::REGION_DETAIL, name: 'region_detail', requirements: ['slug' => '[a-z0-9-]+'])]
    public function regionDetail(string $slug, Request $request, RegionDirectoryProvider $directory, RegionSilhouette $silhouette): Response
    {
        $region = $directory->region($slug, $request->getLocale());
        if (null === $region) {
            throw $this->createNotFoundException(sprintf('No region "%s".', $slug));
        }

        return $this->render('pages/region.html.twig', [
            'page_title' => 'meta.region_title',
            'page_description' => 'meta.region_description',
            'nav_active' => 'regions',
            'region' => $region,
            'silhouette' => $silhouette->forRegion((int) $region['id'], (string) $region['countryCode']),
        ]);
    }

    #[Route(LocalizedPath::COVERAGE, name: 'coverage')]
    public function coverage(Request $request, CoverageStatsProvider $stats): Response
    {
        // The table's order is a query parameter, not a button. A client-side
        // toggle needed an inline script, an inline script needs a CSP nonce,
        // and a nonce is the one thing a shared cache cannot hold
        // (page-caching.md §3.2). As two URLs both orders are cached, and both
        // work without JavaScript. Anything unrecognised falls back rather than
        // 404s: this is a sort order, not an identifier.
        $sort = $request->query->get('sort');
        $sort = CoverageStatsProvider::SORT_TOTAL === $sort
            ? CoverageStatsProvider::SORT_TOTAL
            : CoverageStatsProvider::SORT_DENSITY;

        return $this->render('pages/coverage.html.twig', [
            'page_title' => 'meta.coverage_title',
            'page_description' => 'meta.coverage_description',
            'nav_active' => 'coverage',
            'kpis' => $stats->kpis(),
            'countries' => $stats->countries($request->getLocale(), $sort),
            'sort' => $sort,
            'sort_density' => CoverageStatsProvider::SORT_DENSITY,
            'sort_total' => CoverageStatsProvider::SORT_TOTAL,
            'thinnest' => $stats->thinnestCategories(),
        ]);
    }

    #[Route(LocalizedPath::DEVELOPERS, name: 'developers')]
    public function developers(): Response
    {
        return $this->render('pages/developers.html.twig', [
            'page_title' => 'meta.developers_title',
            'page_description' => 'meta.developers_description',
            'nav_active' => 'developers',
        ]);
    }

    #[Route(LocalizedPath::LICENSES, name: 'licenses')]
    public function licenses(): Response
    {
        return $this->render('pages/licenses.html.twig', [
            'page_title' => 'meta.licenses_title',
            'page_description' => 'meta.licenses_description',
            'nav_active' => 'licenses',
        ]);
    }

    #[Route(LocalizedPath::CREDITS, name: 'credits')]
    public function credits(): Response
    {
        return $this->render('pages/credits.html.twig', [
            'page_title' => 'meta.credits_title',
            'page_description' => 'meta.credits_description',
            'nav_active' => '',
        ]);
    }

    #[Route(LocalizedPath::JOIN, name: 'join')]
    public function join(): Response
    {
        return $this->render('pages/join.html.twig', [
            'page_title' => 'meta.join_title',
            'page_description' => 'meta.join_description',
            'nav_active' => '',
        ]);
    }

    #[Route(LocalizedPath::CONTRIBUTORS, name: 'contributors')]
    public function contributors(Request $request, ContributorWallProvider $wallProvider, PageSize $pageSize): Response
    {
        $q = trim($request->query->getString('q'));
        $country = trim($request->query->getString('country'));

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $wallProvider->wallCount($q, $country),
            $pageSize->resolve(ContributorWallProvider::PER_PAGE),
        );

        return $this->render('pages/contributors.html.twig', [
            'page_title' => 'meta.contributors_title',
            'page_description' => 'meta.contributors_description',
            'nav_active' => '',
            'wall' => $wallProvider->wall($q, $country, $pager['page'], $pager['perPage']),
            'wall_countries' => $wallProvider->wallCountries(),
            'wall_q' => $q,
            'wall_country' => $country,
            'pager' => $pager,
            'pager_params' => array_filter(['q' => $q, 'country' => $country], static fn (string $v): bool => '' !== $v),
            'stats' => $wallProvider->stats(),
        ]);
    }

    #[Route(LocalizedPath::PRIVACY, name: 'privacy')]
    public function privacy(): Response
    {
        return $this->render('pages/privacy.html.twig', [
            'page_title' => 'meta.privacy_title',
            'page_description' => 'meta.privacy_description',
            'nav_active' => '',
        ]);
    }

    #[Route(LocalizedPath::TERMS, name: 'terms')]
    public function terms(): Response
    {
        return $this->render('pages/terms.html.twig', [
            'page_title' => 'meta.terms_title',
            'page_description' => 'meta.terms_description',
            'nav_active' => '',
        ]);
    }

    #[Route(LocalizedPath::PAGES, name: 'pages')]
    public function pages(Request $request): Response
    {
        // Two drawings of one directory. The toggle is a pair of links, so
        // the choice works without a script and a shared cache holds each
        // drawing under its own URL (page-caching.md §3).
        return $this->render('pages/pages.html.twig', [
            'page_title' => 'meta.pages_title',
            'page_description' => 'meta.pages_description',
            'nav_active' => '',
            'view' => 'list' === $request->query->getString('view') ? 'list' : 'map',
        ]);
    }

    #[Route(LocalizedPath::SCOUT, name: 'scout')]
    public function scout(): Response
    {
        return $this->render('pages/scout.html.twig', [
            'page_title' => 'meta.scout_title',
            'page_description' => 'meta.scout_description',
            'nav_active' => '',
        ]);
    }
}
