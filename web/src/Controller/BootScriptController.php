<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The globals every page sets before its other scripts run, as a file.
 *
 * These used to be two inline `<script nonce=…>` blocks in `base.html.twig`,
 * which is what stopped any page being held in a shared cache: a nonce is
 * minted per request, so a stored copy freezes it, and a nonce that many
 * visitors share is a nonce an attacker can simply read
 * (docs/specs/page-caching.md §3.2). As a file the pages that carry it need no
 * nonce at all.
 *
 * It is one route per locale rather than a static asset because the strings
 * are translated, and the build number changes on deploy. Both are constant
 * for a given URL and build, which is exactly what a cache wants. The two
 * values that are genuinely per rider, the date and unit preferences, ride on
 * `<body>` as data attributes and are read back by the script, so this file
 * stays the same for everybody.
 *
 * @see docs/specs/page-caching.md §3.2
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class BootScriptController extends AbstractController
{
    /**
     * How long, in seconds, a browser or a shared cache may keep it.
     *
     * Short, because the build number rides along and a rider on a stale copy
     * is told the wrong version is running. Long enough that it is fetched
     * once a visit rather than once a page.
     */
    private const int MAX_AGE = 300;

    #[Route('/boot.js', name: 'boot_script', methods: ['GET'])]
    public function boot(Request $request): Response
    {
        $body = $this->renderView('boot.js.twig');

        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
        ]);
        $response->setEtag(md5($body));
        // A signed-in rider has a session, and Symfony downgrades any response
        // to private once one is open. Nothing here depends on who is asking,
        // so say so explicitly rather than let the cookie decide.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->isNotModified($request);

        return $response;
    }
}
