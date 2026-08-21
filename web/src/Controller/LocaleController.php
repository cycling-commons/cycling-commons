<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Session locale switcher (GET). Does not write User.locale — that is a CSRF-protected settings POST.
 *
 * @see docs/specs/dev-environment.md §7
 *
 * @api
 */
final class LocaleController extends AbstractController
{
    #[Route('/i18n/{_locale}', name: 'locale_switch', requirements: ['_locale' => 'en|fr|nl|de|es'])]
    public function switch(string $_locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $_locale);

        // Relative `to` only; same allowlist as the pager (docs/specs/account-and-auth.md §9.4).
        $to = $request->query->get('to');
        if (\is_string($to) && $this->isSafeInternalPath($to)) {
            return $this->redirect($to);
        }

        // Same-host Referer only — never an open redirect.
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('home');
    }

    /**
     * Root-relative path allowlist: no `//`, `\`, or C0 — otherwise open-redirect.
     *
     * @see docs/specs/account-and-auth.md §9.4
     */
    private function isSafeInternalPath(string $path): bool
    {
        return 1 === preg_match('#\A/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*\z#', $path);
    }
}
