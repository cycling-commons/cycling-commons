<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Is translate mode active for this request (translations.md §4.1)?
 *
 * Decided once, at `kernel.request` priority 0: LocaleSubscriber has run (20)
 * and so has the firewall (8), so both the locale and the security token are
 * settled. The answer is stored on the main request as an attribute, and every
 * later reader (the translator decorator, the Twig function) reads that.
 *
 * Cleared at `finish_request`, so anything rendered after the response is
 * built is never marked.
 *
 * @api
 */
final class TranslateMode implements EventSubscriberInterface
{
    public const string SESSION_KEY = 'translate_mode';
    public const string ATTR = '_translate_mode';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
    ) {
    }

    /** @return array<string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 0],
            KernelEvents::FINISH_REQUEST => ['onFinish', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::ATTR, $this->decide($event->getRequest()));
    }

    public function onFinish(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::ATTR, false);
        }
    }

    public function isActive(): bool
    {
        return true === $this->requests->getMainRequest()?->attributes->get(self::ATTR);
    }

    /** The locale the marks are for, or null when the mode is not active. */
    public function locale(): ?string
    {
        return $this->isActive() ? $this->requests->getMainRequest()?->getLocale() : null;
    }

    private function decide(Request $request): bool
    {
        // Reading mode, so a reading method. A rider clicks a string on a page
        // they are looking at; nothing a POST answers needs marks, and the
        // drawer's own POST re-render is form fields the browser script strips
        // anyway. The reason this is a rule rather than a tidiness is
        // `POST /settings/export`, which renders `export.readme` through the
        // translator into a ZIP: a translated string inside a downloadable
        // artifact is unreachable by BOTH nets (translations.md §4.1), because
        // one strips a response body and cannot see inside a
        // BinaryFileResponse, and the other is mail only.
        if (!$request->isMethod(Request::METHOD_GET)) {
            return false;
        }

        // The session comes next, and that order is load-bearing rather than
        // tidiness. A visitor with no session cookie is answered here without
        // anything reading the session or the token storage, either of which
        // would open a session and cost every public page its `public`
        // Cache-Control (PublicPageCacheSubscriber says the same in reverse).
        if (!$request->hasPreviousSession() || true !== $request->getSession()->get(self::SESSION_KEY)) {
            return false;
        }
        if (!$this->security->isGranted('ROLE_USER')) {
            return false;
        }

        // The map builds its text in JavaScript from boot.js, and EasyAdmin is
        // not rider-facing copy. The spec says `map` and `map_*`
        // (translations.md §4.1), and this matches exactly that rather than
        // any name starting with the three letters: a future `mapping_*`
        // route would otherwise lose the mode with nothing to say why.
        // Localized route names carry a `.<locale>` suffix (`map.nl`), so the
        // suffix comes off before the comparison.
        $route = (string) $request->attributes->get('_route', '');
        $dot = strrpos($route, '.');
        $bare = false !== $dot ? substr($route, 0, $dot) : $route;
        if ('map' === $bare || str_starts_with($bare, 'map_') || str_starts_with($request->getPathInfo(), '/admin')) {
            return false;
        }

        return $this->mayTranslateLocale($request->getLocale());
    }

    /**
     * May this user propose anything at all in this locale (translations.md
     * §4.1, §4.2)?
     *
     * The locale-and-role half of {@see decide()}, and public so the account
     * chip can ask the same question through the `translate_can_page()` Twig
     * function. It used to hardcode the locale list beside this one, which
     * meant a sixth locale would have been markable while showing no toggle.
     *
     * With no locale given it answers for the current main request, which is
     * what the chip wants.
     */
    public function mayTranslateLocale(?string $locale = null): bool
    {
        $locale ??= $this->requests->getMainRequest()?->getLocale() ?? '';
        if (!$this->security->isGranted('ROLE_USER')) {
            return false;
        }
        if (TranslationLimits::isTranslatableLocale($locale)) {
            return true;
        }

        // English is proposable to curators only (translations.md §4.2).
        return 'en' === $locale && $this->security->isGranted('ROLE_CURATOR');
    }
}
