<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The two budgets behind every endpoint that can make us fetch from a third
 * party: a per-address poll limit that answers 429, and an admission pair for
 * outbound requests. Shared by the coverage photo and the town card.
 *
 * @see docs/specs/coverage-provider.md §7
 * @see docs/specs/security-architecture.md §7
 */
trait ThirdPartyBudget
{
    /**
     * May we admit one more third-party fetch right now?
     *
     * Two budgets, because they stop different attacks. The per-address one
     * stops a single script; the global one is the one that matters, because a
     * distributed script defeats any per-address limit and this is what bounds
     * the request rate Wikimedia ever sees from us, and the rate our own
     * storage grows, however many clients are asking.
     *
     * Both are consumed, deliberately, even when the first refuses: a caller
     * hammering a refused endpoint should not get free global capacity by
     * being blocked locally.
     */
    private function fetchBudgetAllows(Request $request, RateLimiterFactoryInterface $perAddress, RateLimiterFactoryInterface $global): bool
    {
        $mine = $perAddress->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted();
        $ours = $global->create('all')->consume()->isAccepted();

        return $mine && $ours;
    }

    /** Per-address poll limit; 429 + Retry-After. */
    private function rateLimited(Request $request, RateLimiterFactoryInterface $limiter): ?JsonResponse
    {
        $limit = $limiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $response = new JsonResponse(['error' => 'rate_limited'], 429);
        $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}
