<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * An anonymous visitor sent to sign in keeps no session for it.
 *
 * Symfony remembers the page a visitor wanted (`/vote`, `/improve`) in the
 * session before sending them to /login, so the first click on "Vote" or
 * "Contribute" started a session, and from then on every public page skipped
 * the page cache for that visitor (PublicPageCacheSubscriber rules out any
 * request with a session; devOps 2026-09-28). This moves the wanted page out
 * of the session and into the sign-in link, `/login?_target_path=/vote`,
 * which the sign-in form carries on and form_login already reads. The session
 * is empty again, so no cookie is sent.
 *
 * Only a local path travels ({@see self::localPath()}): never another site,
 * and never `//host`, which a browser reads as one.
 *
 * @see docs/specs/page-caching.md
 *
 * @api
 */
final readonly class StatelessLoginRedirectSubscriber implements EventSubscriberInterface
{
    /** The key TargetPathTrait writes for the `main` firewall. */
    private const string TARGET_PATH_KEY = '_security.main.target_path';

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        // Before AbstractSessionListener (-1000), which decides on the cookie.
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$response instanceof RedirectResponse || !$request->hasSession(true)) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted() || !$session->has(self::TARGET_PATH_KEY) || null !== $this->security->getUser()) {
            return;
        }
        $login = $this->urls->generate('login');
        if (parse_url($response->getTargetUrl(), \PHP_URL_PATH) !== $login) {
            return;
        }

        $wanted = self::localPath((string) $session->get(self::TARGET_PATH_KEY));
        $session->remove(self::TARGET_PATH_KEY);
        if (null !== $wanted) {
            $response->setTargetUrl($login.'?'.http_build_query(['_target_path' => $wanted]));
        }
    }

    /**
     * The path and query of a URL on this site, or null for anything that
     * could send a browser elsewhere.
     */
    public static function localPath(?string $url): ?string
    {
        if (null === $url || '' === $url) {
            return null;
        }
        $path = parse_url($url, \PHP_URL_PATH);
        if (!\is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }
        $query = parse_url($url, \PHP_URL_QUERY);

        return $path.(\is_string($query) && '' !== $query ? '?'.$query : '');
    }
}
