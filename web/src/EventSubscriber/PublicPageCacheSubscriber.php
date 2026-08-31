<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks the public pages shareable, so a cache in front may hold them.
 *
 * `/v1/search` refuses a caller after 120 requests a minute. `/` refuses
 * nobody, and it answers `private`, so every hit reaches PHP: one machine
 * sustained 6.5 a second in the measurement of 2026-08-30, and ten cheap cloud
 * addresses would be enough to keep nine PHP-FPM workers busy full time on a
 * page that is identical for every logged-out visitor. A rate limit is the
 * wrong answer to that; a page that is the same for everybody should be
 * rendered once a minute rather than once a visitor.
 *
 * ## What makes this safe
 *
 * The nav shows a rider's own name when they are signed in, so the danger is
 * one rider's page reaching another. Two independent things prevent it, and
 * both have to hold:
 *
 * 1. **Here:** a response is only ever marked public when nobody is signed in
 *    and no session exists. A signed-in page is never labelled shareable in the
 *    first place, so no cache can legitimately store one, wherever it sits.
 * 2. **In nginx:** `proxy_cache_bypass` on the session cookie, so a stored
 *    anonymous copy is never handed to a signed-in rider
 *    (page-caching.md §5). That is the half this file cannot do.
 *
 * There is deliberately no `Vary: Cookie`. It would be the textbook answer for
 * an unknown intermediary, and it would also give every distinct cookie string
 * its own cache entry, which for an analytics cookie means a cache that never
 * hits. Rule 1 is what actually carries the safety: a logged-in response is
 * marked private, so an intermediary storing it would be broken with or
 * without the header.
 *
 * `s-maxage` without browser caching is on purpose. The saving is in the first
 * second of a flood, which is a shared-cache problem; letting a browser hold
 * its own copy only makes a rider's own back button stale.
 *
 * @see docs/specs/page-caching.md §2
 *
 * @api
 */
final readonly class PublicPageCacheSubscriber implements EventSubscriberInterface
{
    /**
     * How long, in seconds, a shared cache may hold a page.
     *
     * Short on purpose. A minute is enough to absorb a flood, and short enough
     * that an edit to a page or a new blog post is never long invisible.
     */
    private const int SHARED_MAX_AGE = 60;

    /**
     * Route names that are the same for every logged-out visitor
     * (page-caching.md §6).
     *
     * Names, not paths, because every one of these has five localized paths.
     * The locale suffix routing adds (`home.en`) is stripped before the lookup.
     *
     * Absent on purpose: `contributors` (a paged wall that moves), `map`
     * (per-rider preferences, and the heaviest page to store), and `coverage`,
     * which belongs here but still carries a nonce'd inline script.
     */
    private const array CACHEABLE_ROUTES = [
        'home',
        'about',
        'developers',
        'developers_api',
        'licenses',
        'credits',
        'privacy',
        'terms',
        'accessibility',
        'roadmap',
        'changelog',
        'regions',
        'region_detail',
        'blog',
        'blog_post',
        'known_issues',
        'pages',
        // The three guarded public forms. They belong here for the same reason
        // as the rest, and they were the last public pages that could not join:
        // each used to mint a single-use proof-of-work challenge into its own
        // markup, which no cache may hold. The forms fetch it now
        // (App\Controller\FormChallengeController), so what is left in the page
        // is the same for everybody. `content_report_answer` is deliberately
        // absent: that URL is a private link for one reporter.
        'contact',
        'content_report',
        'bug_report',
    ];

    /** @param list<string> $enabledLocales */
    public function __construct(
        private Security $security,
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->isShareable($request, $response)) {
            return;
        }

        // Symfony downgrades any response to private once a session is open.
        // Nothing here depends on who is asking, and the guards above have
        // already established there is no session, so say so explicitly.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setPublic();
        $response->setSharedMaxAge(self::SHARED_MAX_AGE);
        // A browser still revalidates: only the shared cache in front keeps a
        // copy, so a rider's own back button never shows a stale page.
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');
    }

    private function isShareable(Request $request, Response $response): bool
    {
        if (!$request->isMethod(Request::METHOD_GET) || Response::HTTP_OK !== $response->getStatusCode()) {
            return false;
        }

        // The route is checked FIRST, and that order is load-bearing rather
        // than tidiness. Asking Security for the user reads the token storage,
        // which touches the session, which makes Symfony's session listener
        // downgrade the response to private. Ask it on every response and this
        // subscriber quietly strips `public` off pages it has no business
        // touching: /changelog.atom, which sets its own, is how that surfaced.
        // Below this line the response is one we are going to label ourselves.
        $route = $request->attributes->get('_route');
        if (!\is_string($route) || !\in_array($this->baseRoute($route), self::CACHEABLE_ROUTES, true)) {
            return false;
        }

        // Anything that identifies a visitor rules the response out, whatever
        // the route says. A signed-in rider's nav carries their own name.
        if (null !== $this->security->getUser()) {
            return false;
        }
        // A session the visitor already carries means a cookie came in, and
        // whatever is in it may have shaped the page.
        if ($request->hasPreviousSession()) {
            return false;
        }
        // A session that is merely *started* is not enough to rule the page
        // out, and testing for that alone would rule out every page: anything
        // that so much as looks at the session opens one, this subscriber
        // included. What matters is whether it holds anything, because an empty
        // session sends no cookie and cannot have personalised anything. That
        // distinction is load-bearing elsewhere too: it is why the bug button's
        // CSRF token is stateless (config/packages/csrf.yaml), so that a reader
        // who never signs in never gets a session cookie or a Redis entry.
        if ($request->hasSession() && $request->getSession()->isStarted()
            && [] !== $request->getSession()->all()) {
            return false;
        }

        // Belt and braces. The session cookie itself is set after this runs, so
        // this catches the other kinds, not that one.
        return [] === $response->headers->getCookies();
    }

    /** `home.en` is the English arm of `home`; the allowlist names the route once. */
    private function baseRoute(string $route): string
    {
        foreach ($this->enabledLocales as $locale) {
            if (str_ends_with($route, '.'.$locale)) {
                return substr($route, 0, -\strlen($locale) - 1);
            }
        }

        return $route;
    }
}
