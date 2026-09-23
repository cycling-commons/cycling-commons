<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The retired HTML prototype's pages, each now served under the same name.
 *
 * @api
 */
final class LegacyDemoRedirectController extends AbstractController
{
    public const array PAGES = [
        'about', 'add-climb', 'contribute', 'contributors', 'coverage', 'developers',
        'improve', 'join', 'licenses', 'login', 'map', 'moderate', 'pages', 'privacy',
        'profile', 'region', 'regions', 'settings', 'terms', 'vote',
    ];

    private const string PAGE_PATTERN = 'about|add-climb|contribute|contributors|coverage|developers|improve|join|licenses|login|map|moderate|pages|privacy|profile|region|regions|settings|terms|vote';

    #[Route('/index.html', name: 'legacy_demo_index', methods: ['GET', 'HEAD'])]
    #[Route('/{page}.html', name: 'legacy_demo_page', requirements: ['page' => self::PAGE_PATTERN], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $page = ''): RedirectResponse
    {
        $qs = $request->getQueryString();

        return new RedirectResponse(('' === $page ? '/' : '/'.$page).(null !== $qs ? '?'.$qs : ''), 301);
    }
}
