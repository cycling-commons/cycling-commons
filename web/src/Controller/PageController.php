<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-renders the static content pages through the shared Twig layout.
 * page_title/page_description are translation keys (see the `meta` catalog
 * group); the layout translates them in the <head>.
 *
 * The class-level localized prefix serves English clean (`/regions`) and the
 * other locales under a path prefix (`/fr/regions`); Symfony sets `_locale`
 * from the matched path.
 *
 * @api Instantiated by Symfony's router, never referenced from code — `@api`
 *      tells Psalm this (and its actions) is a live entry point, not dead code.
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
    public function regions(): Response
    {
        return $this->render('pages/regions.html.twig', [
            'page_title' => 'meta.regions_title',
            'page_description' => 'meta.regions_description',
            'nav_active' => 'regions',
        ]);
    }

    #[Route('/coverage', name: 'coverage')]
    public function coverage(): Response
    {
        return $this->render('pages/coverage.html.twig', [
            'page_title' => 'meta.coverage_title',
            'page_description' => 'meta.coverage_description',
            'nav_active' => 'coverage',
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
    public function contributors(): Response
    {
        return $this->render('pages/contributors.html.twig', [
            'page_title' => 'meta.contributors_title',
            'page_description' => 'meta.contributors_description',
            'nav_active' => '',
        ]);
    }

    #[Route('/region', name: 'region')]
    public function region(): Response
    {
        return $this->render('pages/region.html.twig', [
            'page_title' => 'meta.region_title',
            'page_description' => 'meta.region_description',
            'nav_active' => 'regions',
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
}
