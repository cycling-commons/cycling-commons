<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-renders the static content pages through the shared Twig layout.
 *
 * @api Instantiated by Symfony's router, never referenced from code — `@api`
 *      tells Psalm this (and its actions) is a live entry point, not dead code.
 */
final class PageController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->render('pages/index.html.twig', [
            'page_title' => 'Cycling Commons',
            'page_description' => "An open, community-built map of the world's best riding.",
            'nav_active' => '',
        ]);
    }

    #[Route('/about', name: 'about')]
    public function about(): Response
    {
        return $this->render('pages/about.html.twig', [
            'page_title' => 'Cycling Commons — About',
            'page_description' => "Cycling Commons is an open, community-built map of the world's best riding. Open data anyone can use, governed by the riders who build it.",
            'nav_active' => 'about',
        ]);
    }

    #[Route('/regions', name: 'regions')]
    public function regions(): Response
    {
        return $this->render('pages/regions.html.twig', [
            'page_title' => 'Cycling Commons — Regions',
            'page_description' => 'The Cycling Commons is organised into regions of roughly Wallonia/Flanders size — browse them by continent.',
            'nav_active' => 'regions',
        ]);
    }

    #[Route('/coverage', name: 'coverage')]
    public function coverage(): Response
    {
        return $this->render('pages/coverage.html.twig', [
            'page_title' => 'Cycling Commons — Coverage',
            'page_description' => 'How complete is the map? See what\'s covered, what\'s thin, and where to help — transparent monitoring of the open cycling Commons.',
            'nav_active' => 'coverage',
        ]);
    }

    #[Route('/vote', name: 'vote')]
    public function vote(): Response
    {
        return $this->stub('Vote', 'vote');
    }

    #[Route('/developers', name: 'developers')]
    public function developers(): Response
    {
        return $this->render('pages/developers.html.twig', [
            'page_title' => 'Cycling Commons — Developers',
            'page_description' => 'An open API, free for anyone to build on. Query the Commons by type and area, or pull bulk dumps per country — open by default.',
            'nav_active' => 'developers',
        ]);
    }

    #[Route('/licenses', name: 'licenses')]
    public function licenses(): Response
    {
        return $this->render('pages/licenses.html.twig', [
            'page_title' => 'Cycling Commons — Licence',
            'page_description' => 'The short version, in plain language — what you can do with Commons data, media, and code — ODbL data, CC BY-SA media, PolyForm Shield (source-available) code.',
            'nav_active' => 'licenses',
        ]);
    }

    #[Route('/join', name: 'join')]
    public function join(): Response
    {
        return $this->render('pages/join.html.twig', [
            'page_title' => 'Cycling Commons — Get involved',
            'page_description' => 'Help build the open cycling commons — riders with local knowledge, developers, social, fundraisers, and legal. Code, knowledge, or a few hours of expertise: there\'s room for you.',
            'nav_active' => '',
        ]);
    }

    #[Route('/contributors', name: 'contributors')]
    public function contributors(): Response
    {
        return $this->render('pages/contributors.html.twig', [
            'page_title' => 'Cycling Commons — Contributors',
            'page_description' => 'Built by riders. Recognition without surveillance — we celebrate contributions to the open Commons, never personal data.',
            'nav_active' => '',
        ]);
    }

    #[Route('/region', name: 'region')]
    public function region(): Response
    {
        return $this->render('pages/region.html.twig', [
            'page_title' => 'Cycling Commons — Region',
            'page_description' => "Wallonia's best riding in one open layer — the Ardennes classics: climbs, views and routes, kept fresh by the riders who know them.",
            'nav_active' => 'regions',
        ]);
    }

    #[Route('/privacy', name: 'privacy')]
    public function privacy(): Response
    {
        return $this->render('pages/privacy.html.twig', [
            'page_title' => 'Cycling Commons — Privacy',
            'page_description' => 'How the Cycling Commons handles personal data — in plain language. Built to need as little as possible, and to keep what you contribute separate from who you are. GDPR-aligned.',
            'nav_active' => '',
        ]);
    }

    #[Route('/terms', name: 'terms')]
    public function terms(): Response
    {
        return $this->render('pages/terms.html.twig', [
            'page_title' => 'Cycling Commons — Terms',
            'page_description' => 'The terms for using the Cycling Commons site and account — short, readable, and aligned with the open licences. Governed by the law of the Netherlands.',
            'nav_active' => '',
        ]);
    }

    #[Route('/pages', name: 'pages')]
    public function pages(): Response
    {
        return $this->render('pages/pages.html.twig', [
            'page_title' => 'Cycling Commons — All pages',
            'page_description' => 'The whole Commons, page by page — a tour of every public, contributor and curator surface.',
            'nav_active' => '',
        ]);
    }

    #[Route('/contribute', name: 'contribute')]
    public function contribute(): Response
    {
        return $this->stub('Contribute');
    }

    #[Route('/improve', name: 'improve')]
    public function improve(): Response
    {
        return $this->stub('Improve');
    }

    #[Route('/profile', name: 'profile')]
    public function profile(): Response
    {
        return $this->stub('Account');
    }

    // --- Stub routes: real implementations land in later plans. ---

    private function stub(string $title, string $navActive = ''): Response
    {
        return $this->render('stub.html.twig', [
            'page_title' => $title,
            'nav_active' => $navActive,
        ]);
    }
}
