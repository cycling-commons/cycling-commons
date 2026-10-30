<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The governance page lives on the wiki; /governance on the site leads there,
 * as the footer, the about page and the legal pages already do.
 *
 * @api
 */
final class GovernanceRedirectController
{
    public function __construct(
        #[Autowire('%env(WIKI_URL)%')]
        private readonly string $wikiUrl,
    ) {
    }

    #[Route([
        'en' => '/governance',
        'fr' => '/fr/governance',
        'nl' => '/nl/governance',
        'de' => '/de/governance',
        'es' => '/es/governance',
    ], name: 'governance', methods: ['GET', 'HEAD'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->wikiUrl.'/governance/', 301);
    }
}
