<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->stub('Cycling Commons');
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
        return $this->stub('Regions', 'regions');
    }

    #[Route('/coverage', name: 'coverage')]
    public function coverage(): Response
    {
        return $this->stub('Coverage', 'coverage');
    }

    #[Route('/vote', name: 'vote')]
    public function vote(): Response
    {
        return $this->stub('Vote', 'vote');
    }

    #[Route('/developers', name: 'developers')]
    public function developers(): Response
    {
        return $this->stub('Developers', 'developers');
    }

    #[Route('/licenses', name: 'licenses')]
    public function licenses(): Response
    {
        return $this->stub('Licence', 'licenses');
    }

    #[Route('/join', name: 'join')]
    public function join(): Response
    {
        return $this->stub('Get involved');
    }

    #[Route('/contributors', name: 'contributors')]
    public function contributors(): Response
    {
        return $this->stub('Contributors');
    }

    #[Route('/region', name: 'region')]
    public function region(): Response
    {
        return $this->stub('Region', 'regions');
    }

    #[Route('/privacy', name: 'privacy')]
    public function privacy(): Response
    {
        return $this->stub('Privacy');
    }

    #[Route('/terms', name: 'terms')]
    public function terms(): Response
    {
        return $this->stub('Terms');
    }

    #[Route('/pages', name: 'pages')]
    public function pages(): Response
    {
        return $this->stub('All pages');
    }

    #[Route('/map', name: 'map')]
    public function map(): Response
    {
        return $this->stub('Map');
    }

    #[Route('/login', name: 'login')]
    public function login(): Response
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
