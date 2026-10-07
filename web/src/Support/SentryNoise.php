<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Decides which errors are the site answering a stranger correctly, and so
 * never reach GlitchTip.
 *
 * Three answers filled the tracker without being faults, and each one can
 * also come from a real fault. The rule for each keeps the fault:
 *
 *  - A `Host` outside TRUSTED_HOSTS (400). Dropped when the host is an IP
 *    address or not a host name at all: scanners walking the load balancer's
 *    address. A name is reported: it means a DNS record points at us that
 *    TRUSTED_HOSTS does not list, as `api.cyclingcommons.org` did, or a
 *    TRUSTED_HOSTS edit that locks real visitors out.
 *  - A 404. Dropped unless the Referer is a page on the same host: then one
 *    of our own pages links to something that is not there.
 *  - An AccessDeniedException. Dropped unless the visitor is fully signed in.
 *    The firewall answers everyone else with the login page (no session, a
 *    remember-me cookie, a 2FA step still open); a fully signed-in visitor
 *    gets a 403, which can be a real permission mistake. The trust resolver
 *    is the firewall's own, so the two cannot draw the line in different
 *    places.
 *
 * Only an error inside a request can be noise. Without one (a Messenger
 * worker, a console command) everything is reported.
 *
 * @see docs/specs/operations.md §1
 *
 * @api
 */
final readonly class SentryNoise
{
    public function __construct(
        private RequestStack $requests,
        private TokenStorageInterface $tokens,
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private AuthenticationTrustResolverInterface $trust,
    ) {
    }

    public function isNoise(?\Throwable $error): bool
    {
        $request = $this->requests->getMainRequest();
        if (null === $error || null === $request) {
            return false;
        }

        // The kernel wraps some answers (a bad Host becomes a
        // BadRequestHttpException), so the whole chain is read.
        for ($e = $error; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof SuspiciousOperationException) {
                return !self::isHostName(self::rawHost($request));
            }
            if ($e instanceof NotFoundHttpException) {
                return !$this->cameFromOurPage($request);
            }
            if ($e instanceof AccessDeniedException) {
                $token = $this->tokens->getToken();

                return null === $token || !$this->trust->isFullFledged($token);
            }
        }

        return false;
    }

    private function cameFromOurPage(Request $request): bool
    {
        $referer = $request->headers->get('referer');
        if (null === $referer) {
            return false;
        }
        $from = parse_url($referer, \PHP_URL_HOST);

        return \is_string($from) && strtolower($from) === self::rawHost($request);
    }

    /**
     * The Host as the client sent it, lower-cased and without its port.
     * Request::getHost() cannot be asked: for an untrusted host it throws
     * once and answers '' after that.
     */
    private static function rawHost(Request $request): string
    {
        $host = (string) ($request->headers->get('host') ?: $request->server->get('SERVER_NAME', ''));

        return strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
    }

    private static function isHostName(string $host): bool
    {
        // An IPv6 literal arrives in brackets: [2001:db8::1]
        if (false !== filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP)) {
            return false;
        }

        return '' !== $host && false !== filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME);
    }
}
