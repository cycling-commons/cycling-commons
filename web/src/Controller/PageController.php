<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Catalog\ContributorWallProvider;
use App\Catalog\CoverageStatsProvider;
use App\Catalog\RegionDirectoryProvider;
use App\Catalog\RegionSilhouette;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
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

    #[Route('/about', name: 'about')]
    public function about(): Response
    {
        return $this->render('pages/about.html.twig', [
            'page_title' => 'meta.about_title',
            'page_description' => 'meta.about_description',
            'nav_active' => 'about',
        ]);
    }

    #[Route('/regions', name: 'regions')]
    public function regions(Request $request, RegionDirectoryProvider $directory, Connection $db): Response
    {
        $locale = $request->getLocale();
        $codes = $db->fetchFirstColumn('SELECT iso2 FROM world_country ORDER BY iso2');
        $allCountries = [];
        foreach ($codes as $code) {
            $allCountries[] = [
                'code' => (string) $code,
                'name' => Countries::exists((string) $code) ? Countries::getName((string) $code, $locale) : (string) $code,
            ];
        }
        // Collator (locale rules), not strcoll (byte order).
        $collator = new \Collator($locale);
        usort($allCountries, static fn (array $a, array $b): int => $collator->compare($a['name'], $b['name']));

        return $this->render('pages/regions.html.twig', [
            'page_title' => 'meta.regions_title',
            'page_description' => 'meta.regions_description',
            'nav_active' => 'regions',
            'countries' => $directory->directory($request->getLocale()),
            'all_countries' => $allCountries,
        ]);
    }

    /** The pre-DB showcase URL; permanent because the old path was linked externally. */
    #[Route('/region', name: 'region')]
    public function region(): Response
    {
        return $this->redirectToRoute('region_detail', ['slug' => 'wallonia'], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/regions/{slug}', name: 'region_detail', requirements: ['slug' => '[a-z0-9-]+'])]
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

    #[Route('/coverage', name: 'coverage')]
    public function coverage(Request $request, CoverageStatsProvider $stats): Response
    {
        return $this->render('pages/coverage.html.twig', [
            'page_title' => 'meta.coverage_title',
            'page_description' => 'meta.coverage_description',
            'nav_active' => 'coverage',
            'kpis' => $stats->kpis(),
            'countries' => $stats->countries($request->getLocale()),
            'thinnest' => $stats->thinnestCategories(),
        ]);
    }

    #[Route('/developers', name: 'developers')]
    public function developers(): Response
    {
        return $this->render('pages/developers.html.twig', [
            'page_title' => 'meta.developers_title',
            'page_description' => 'meta.developers_description',
            'nav_active' => 'developers',
        ]);
    }

    #[Route('/licenses', name: 'licenses')]
    public function licenses(): Response
    {
        return $this->render('pages/licenses.html.twig', [
            'page_title' => 'meta.licenses_title',
            'page_description' => 'meta.licenses_description',
            'nav_active' => 'licenses',
        ]);
    }

    #[Route('/credits', name: 'credits')]
    public function credits(): Response
    {
        return $this->render('pages/credits.html.twig', [
            'page_title' => 'meta.credits_title',
            'page_description' => 'meta.credits_description',
            'nav_active' => '',
        ]);
    }

    #[Route('/join', name: 'join')]
    public function join(): Response
    {
        return $this->render('pages/join.html.twig', [
            'page_title' => 'meta.join_title',
            'page_description' => 'meta.join_description',
            'nav_active' => '',
        ]);
    }

    #[Route('/contributors', name: 'contributors')]
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

    #[Route('/privacy', name: 'privacy')]
    public function privacy(): Response
    {
        return $this->render('pages/privacy.html.twig', [
            'page_title' => 'meta.privacy_title',
            'page_description' => 'meta.privacy_description',
            'nav_active' => '',
        ]);
    }

    #[Route('/terms', name: 'terms')]
    public function terms(): Response
    {
        return $this->render('pages/terms.html.twig', [
            'page_title' => 'meta.terms_title',
            'page_description' => 'meta.terms_description',
            'nav_active' => '',
        ]);
    }

    #[Route('/pages', name: 'pages')]
    public function pages(): Response
    {
        return $this->render('pages/pages.html.twig', [
            'page_title' => 'meta.pages_title',
            'page_description' => 'meta.pages_description',
            'nav_active' => '',
        ]);
    }

    #[Route('/scout', name: 'scout')]
    public function scout(): Response
    {
        return $this->render('pages/scout.html.twig', [
            'page_title' => 'meta.scout_title',
            'page_description' => 'meta.scout_description',
            'nav_active' => '',
        ]);
    }
}
