<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Language switcher endpoint: records the chosen locale in the session (so it
 * applies for the whole visit), then returns to the page they came from.
 *
 * This is a GET link in the nav, so it deliberately performs NO persistent
 * account mutation — a state-changing GET has no CSRF protection and could be
 * triggered cross-site (e.g. an <img> tag) to silently flip a logged-in user's
 * stored language. The account's saved locale is owned by the
 * CSRF-protected settings form; the session locale here covers the visit.
 *
 * @api Instantiated by Symfony's router.
 */
final class LocaleController extends AbstractController
{
    #[Route('/i18n/{_locale}', name: 'locale_switch', requirements: ['_locale' => 'en|fr|nl|de|es'])]
    public function switch(string $_locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $_locale);

        // Preferred: the caller (nav switcher) passes `to` — the current page
        // already re-generated in the target locale, so localized routes land
        // on their prefixed path. Only accept our own relative paths.
        $to = $request->query->get('to');
        if (\is_string($to) && $this->isSafeInternalPath($to)) {
            return $this->redirect($to);
        }

        // Fallback: return to the originating page, but only if it is our host.
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('home');
    }

    /**
     * A path we may redirect to must be root-relative and not protocol-relative
     * (`//host`) or a backslash trick — otherwise it is an open-redirect vector.
     *
     * One strict regex, not prefix checks (review 2026-08-16 finding 8):
     * browsers strip tab/CR/LF *inside* URLs before resolving them, so
     * "/\t//evil.example" passes a `//` prefix check yet leaves the browser as
     * protocol-relative "//evil.example". Hence no C0 control or DEL anywhere
     * in the string, no second character `/`, and no backslash at any position
     * (browsers normalise `\` to `/` in URLs, so "/\evil.example"-style
     * payloads are the same trick in another coat).
     */
    private function isSafeInternalPath(string $path): bool
    {
        return 1 === preg_match('#\A/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*\z#', $path);
    }
}
