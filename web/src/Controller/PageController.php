<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Controller;

use App\Catalog\BestOfPreview;
use App\Catalog\BikeType;
use App\Catalog\ContributorWallProvider;
use App\Catalog\CoverageStatsProvider;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\ItemType;
use App\Catalog\OperationalRegions;
use App\Catalog\RegionDirectoryProvider;
use App\Catalog\RegionRegistryProvider;
use App\Catalog\RegionSilhouette;
use App\Catalog\Season;
use App\Community\CommunityProgress;
use App\Content\ReleaseNotes;
use App\Entity\User;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\World\CuratorScopes;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

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

    /** Which rows countryShapes() draws. 2 = operational rows only, not every outline. */
    private const string SHAPE_RULE = 'v2';

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
    /**
     * One shape per country as GeoJSON, for the world maps on /regions, /best and /coverage (owner
     * 2026-09-08: "a map version where you select the country on a world map",
     * then "do not show the regions on the map, just the countries"). Each
     * shape is the union of that country's stored `region.outline` rings,
     * the simplified ones the map's scope registry serves, so PostGIS merges a
     * few thousand points per country in about a second for all nineteen; the
     * full geometries took 36 seconds for five. Kept in the app cache for a
     * day, keyed on the region table's last change, so an onboarding shows.
     */
    #[Route('/regions/outlines.json', name: 'regions_outlines', methods: ['GET'])]
    public function regionOutlines(Request $request, Connection $db, CacheInterface $cache): Response
    {
        /** @var array{n: int|string, at: string|null} $stamp */
        $stamp = $db->fetchAssociative('SELECT COUNT(*) AS n, MAX(updated_at)::text AS at FROM region WHERE outline IS NOT NULL') ?: ['n' => 0, 'at' => null];
        // The shape rule is part of the key, not only the data: the key used to
        // move only when `region` changed, so changing which rows are drawn
        // left a deploy serving yesterday's whole-country shapes for a day.
        // Bump SHAPE_RULE whenever countryShapes() draws something different.
        $key = 'regions-outlines-'.self::SHAPE_RULE.'-'.substr(hash('xxh128', $stamp['n'].'|'.(string) $stamp['at']), 0, 16);

        $json = $cache->get($key, function (ItemInterface $item) use ($db): string {
            $item->expiresAfter(86400);

            return $this->countryShapes($db);
        });
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        $response->setEtag(md5($json));
        $response->isNotModified($request);

        return $response;
    }

    /** The FeatureCollection itself: one Feature per country, `cc` as its property. */
    private function countryShapes(Connection $db): string
    {
        // Operational rows only, never every row. A country onboarded state by
        // state also carries its level-2 outline, and unioning that in painted
        // the whole United States for two states' worth of coverage: a rider
        // in Ohio saw their state filled in on three pages and found nothing
        // there (owner 2026-09-14). The predicate picks the deepest level the
        // country has, so the United States draws California and Colorado,
        // and Slovenia, which is onboarded as one whole-country region, still
        // draws the country.
        /** @var list<array{cc: string, outline: string}> $rows */
        $rows = $db->fetchAllAssociative(
            'SELECT region.country_code AS cc, region.outline FROM region
              WHERE region.outline IS NOT NULL AND '.OperationalRegions::predicate('region').'
              ORDER BY region.country_code, region.slug',
        );
        $parts = [];
        foreach ($rows as $row) {
            // Flat [x,y,x,y,…] per ring, as the scope registry stores it; each ring is one part, never a hole.
            $rings = json_decode($row['outline'], true);
            if (!\is_array($rings)) {
                continue;
            }
            /** @var mixed $ring */
            foreach ($rings as $ring) {
                if (!\is_array($ring) || \count($ring) < 8) {
                    continue;
                }
                $flat = array_values(array_map(static fn (mixed $v): float => (float) $v, $ring));
                $pairs = [];
                for ($i = 0, $n = \count($flat) - 1; $i < $n; $i += 2) {
                    $pairs[] = [round($flat[$i], 3), round($flat[$i + 1], 3)];
                }
                if (\count($pairs) < 4) {
                    continue;
                }
                if (end($pairs) !== $pairs[0]) {
                    $pairs[] = $pairs[0];
                }
                $parts[] = ['cc' => $row['cc'], 'g' => json_encode(['type' => 'Polygon', 'coordinates' => [$pairs]], \JSON_THROW_ON_ERROR)];
            }
        }
        $features = [];
        if ([] !== $parts) {
            /** @var list<array{cc: string, shape: string}> $merged */
            $merged = $db->fetchAllAssociative(
                'SELECT t.cc, ST_AsGeoJSON(ST_Union(ST_MakeValid(ST_GeomFromGeoJSON(t.g))), 3) AS shape
                   FROM json_to_recordset(CAST(:parts AS json)) AS t(cc text, g text)
                  GROUP BY t.cc ORDER BY t.cc',
                ['parts' => json_encode($parts, \JSON_THROW_ON_ERROR)],
            );
            foreach ($merged as $row) {
                $features[] = ['type' => 'Feature', 'properties' => ['cc' => $row['cc']], 'geometry' => json_decode($row['shape'], true, 512, \JSON_THROW_ON_ERROR)];
            }
        }

        return json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

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

    /**
     * The two roles, side by side: what a contributor does and what a curator
     * does (owner 2026-09-08: "a specific page about what a contributor/
     * moderator does"; one page, two halves). Curator is the word: in code it
     * is the moderator role.
     *
     * @see docs/specs/moderation-and-contribution.md §8
     */
    #[Route(LocalizedPath::ROLES, name: 'roles')]
    public function roles(CommunityProgress $progress): Response
    {
        return $this->render('pages/roles.html.twig', [
            'page_title' => 'meta.roles_title',
            'page_description' => 'meta.roles_description',
            'nav_active' => '',
            'progress' => $progress->summary(),
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
    public function regions(Request $request, RegionDirectoryProvider $directory, Connection $db, CuratorScopes $scopes): Response
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

        // The areas inside countries the Commons already reaches, so somebody
        // can type "Ohio" and land somewhere. A country being here does not
        // mean every part of it is, and a box that only knows country names
        // answers a rider's own region with silence (owner 2026-09-13).
        //
        // Onboarded countries only, which is both smaller and honest: the area
        // form exists only on an onboarded country's page, so every hit here
        // leads to a form that works, and for the rest the answer is to ask
        // for the country, which typing its name already does.
        $allAreas = array_map(
            static fn (array $a): array => [
                'name' => $a['name'],
                'cc' => $a['cc'],
                'country' => Countries::exists($a['cc'])
                    ? Countries::getName($a['cc'], $locale)
                    : $a['cc'],
            ],
            $scopes->forCountries(array_keys($onboarded)),
        );

        // A signed-in rider's globe starts turned to their base (owner
        // 2026-09-08). Safe on a cached route: a signed-in response is never
        // marked shareable (PublicPageCacheSubscriber rule 1).
        $user = $this->getUser();
        $home = null;
        if ($user instanceof User && null !== $user->getBaseLat() && null !== $user->getBaseLng()) {
            $home = [$user->getBaseLng(), $user->getBaseLat()];
        }

        return $this->render('pages/regions.html.twig', [
            'page_title' => 'meta.regions_title',
            'page_description' => 'meta.regions_description',
            'nav_active' => 'regions',
            'countries' => $directoryList,
            'all_countries' => $allCountries,
            'all_areas' => $allAreas,
            'home' => $home,
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

    /**
     * What riders rate best, as it will look once the ballot opens.
     *
     * **A design preview with invented tallies** (owner 2026-09-12: "for now
     * like in the demo we need to simulate a page, this can also help us
     * design the voting specs"). `route_vote` is empty and stays empty until
     * the ballot ships, so a page built on real counts would be blank
     * everywhere and could settle nothing. The names are real rows; only the
     * numbers are made up, and {@see BestOfPreview} derives them from each
     * row's id so a reload never reshuffles anything.
     *
     * Public, unlike `/vote`: the whole point of this one is that somebody who
     * has not joined can see what the vote produced.
     *
     * Every choice is a query parameter and every control a link, the same
     * rule the sort on /coverage follows: no inline script, no nonce, and a
     * shared cache can hold each combination (page-caching.md §3.2).
     */
    #[Route(LocalizedPath::BEST_OF, name: 'best_of')]
    public function bestOf(Request $request, BestOfPreview $preview, RegionRegistryProvider $regions): Response
    {
        $categories = BestOfPreview::categories();
        $wanted = (string) $request->query->get('cat', '');
        $type = ItemType::fromParam('' === $wanted ? $categories[0]->value : $wanted);
        if (!$type->isVotable()) {
            $type = $categories[0];
        }

        $season = Season::tryFrom((string) $request->query->get('season', '')) ?? Season::current(new \DateTimeImmutable());

        // An unknown country is "everywhere" rather than a 404: this is a
        // browsing control, not an identifier.
        $cc = strtoupper((string) $request->query->get('cc', ''));
        $countries = $this->bestOfCountries($regions, $request->getLocale());
        $cc = \array_key_exists($cc, $countries) ? $cc : null;

        // Comma-separated, because these three are questions with more than
        // one honest answer: a rider who is happy on gravel or a mountain bike
        // is asking about both at once (owner 2026-09-12).
        $bikes = self::pickMany($request, 'bike', BikeType::values());
        $lengths = self::pickMany($request, 'len', array_keys(BestOfPreview::LENGTHS));
        $difficulties = self::pickMany($request, 'diff', array_values(DifficultyVocabulary::LABELS));

        return $this->render('pages/best_of.html.twig', [
            'page_title' => 'meta.best_of_title',
            'page_description' => 'meta.best_of_description',
            'nav_active' => 'best_of',
            'categories' => $categories,
            'category' => $type,
            'seasons' => Season::cases(),
            'season' => $season,
            'countries' => $countries,
            'country' => $cc,
            'bikes' => BikeType::values(),
            'bike' => $bikes,
            'difficulties' => array_values(DifficultyVocabulary::LABELS),
            'difficulty' => $difficulties,
            'lengths' => array_keys(BestOfPreview::LENGTHS),
            'length' => $lengths,
            'ranking' => $preview->ranking($type, $season, $cc, $bikes, $difficulties, null, $lengths),
            // A country big enough to have regions is asked region by region:
            // one national top ten flattens the Alps into the Ardennes and
            // tells a rider near neither of them anything (owner 2026-09-12).
            'regions' => null === $cc ? null : $preview->byRegion($type, $season, $cc, $bikes, $difficulties, BestOfPreview::TOP_N, $lengths),
        ]);
    }

    /**
     * The values a multi-choice filter was given, keeping only known ones.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function pickMany(Request $request, string $key, array $allowed): array
    {
        $raw = explode(',', (string) $request->query->get($key, ''));

        return array_values(array_unique(array_filter(
            array_map(trim(...), $raw),
            static fn (string $v): bool => \in_array($v, $allowed, true),
        )));
    }

    /**
     * The countries the scope picker may offer, code to name.
     *
     * Only the ones with an operational region, so a chip never leads to a
     * list that was always going to be empty.
     *
     * @return array<string, string>
     */
    private function bestOfCountries(RegionRegistryProvider $regions, string $locale): array
    {
        $out = [];
        foreach ($regions->all() as $region) {
            $code = (string) $region['countryCode'];
            // The reader's own language, the same source the world tables use.
            $out[$code] = \Locale::getDisplayRegion('-'.$code, $locale);
        }
        asort($out);

        return $out;
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
        // The view is not a query parameter. Both views ship in one response
        // and a class on <html> decides which is shown, so /coverage is one
        // URL and one cached body per sort order (page-caching.md §3.2).
        $countries = $stats->countries($request->getLocale(), $sort);

        return $this->render('pages/coverage.html.twig', [
            'page_title' => 'meta.coverage_title',
            'page_description' => 'meta.coverage_description',
            'nav_active' => 'coverage',
            'kpis' => $stats->kpis(),
            'countries' => $countries,
            'sort' => $sort,
            'sort_density' => CoverageStatsProvider::SORT_DENSITY,
            'sort_total' => CoverageStatsProvider::SORT_TOTAL,
            // A sort of the country list already in memory, not a query, so
            // the globe half of the response costs nothing to prepare.
            'density_classes' => CoverageStatsProvider::densityClasses($countries),
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
