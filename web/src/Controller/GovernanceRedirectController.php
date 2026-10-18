<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

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
    public const string WIKI_GOVERNANCE = 'https://wiki.cyclingcommons.org/governance/';

    #[Route([
        'en' => '/governance',
        'fr' => '/fr/governance',
        'nl' => '/nl/governance',
        'de' => '/de/governance',
        'es' => '/es/governance',
    ], name: 'governance', methods: ['GET', 'HEAD'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse(self::WIKI_GOVERNANCE, 301);
    }
}
