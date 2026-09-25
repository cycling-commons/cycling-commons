<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Routing\ActiveLocales;
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
    public function switch(string $_locale, Request $request, ActiveLocales $activeLocales): Response
    {
        // The route requirement lists every BUILT language, because it is
        // compiled in; whether this deployment serves one is a runtime
        // question. A language it does not serve switches to the default
        // locale, as its prefixed paths do (dev-environment.md §7 i18n).
        if (!$activeLocales->isActive($_locale)) {
            $_locale = $activeLocales->defaultLocale();
        }

        $request->getSession()->set('_locale', $_locale);

        // Relative `to` only; same allowlist as the pager (docs/specs/account-and-auth.md §9.4).
        $to = $request->query->get('to');
        if (\is_string($to) && $this->isSafeInternalPath($to)) {
            return $this->redirect($to);
        }

        // Same-host Referer only — never an open redirect.
        //
        // Compare the parsed HOST, never a string prefix: prefix matching on
        // "https://cyclingcommons.org" also accepts
        // "https://cyclingcommons.org.evil.example/", which is a different site
        // (security scan 2026-08-25). Scheme is checked too, so an http Referer
        // cannot bounce an https visitor down to plaintext.
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && $this->isSameOrigin($referer, $request)) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('home');
    }

    /**
     * True only when $url's scheme, host and port all equal the current request's.
     *
     * @see docs/specs/account-and-auth.md §9.4
     */
    private function isSameOrigin(string $url, Request $request): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== strtolower($request->getScheme())) {
            return false;
        }

        // Default port when absent, so "https://host" and "https://host:443" agree.
        $port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);

        return 0 === strcasecmp($parts['host'], $request->getHost())
            && $port === $request->getPort();
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
