<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The conventions every /v1 endpoint shares (docs/specs/public-api.md §2.2):
 * the per-IP limiter, JSON errors `{error, message}`, the public cache
 * discipline, the bbox, limit and cursor parameters. One place, so twelve
 * endpoints cannot drift into twelve dialects.
 *
 * @api
 */
final readonly class ApiResponder
{
    /** Viewport bbox cap (degrees per axis). */
    public const float BBOX_MAX_SPAN_DEG = 10.0;

    public const int LIMIT_DEFAULT = 100;
    public const int LIMIT_MAX = 500;

    public function __construct(
        private RateLimiterFactoryInterface $publicApiReadLimiter,
    ) {
    }

    /** Per-IP limiter for every read; 429 + Retry-After. */
    public function rateLimited(Request $request): ?JsonResponse
    {
        $limit = $this->publicApiReadLimiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $response = $this->error('rate_limited', 'over 120 requests per minute; slow down', 429);
        $response->headers->set('Retry-After', (string) max(0, $limit->getRetryAfter()->getTimestamp() - time()));

        return $response;
    }

    public function error(string $error, string $message, int $status = 400): JsonResponse
    {
        $response = new JsonResponse(['error' => $error, 'message' => $message], $status);

        return $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function notFound(string $message): JsonResponse
    {
        return $this->error('not_found', $message, 404);
    }

    /**
     * Public cache; do not let a session cookie downgrade it.
     *
     * @see docs/specs/account-and-auth.md §5
     *
     * @param array<string, mixed> $payload
     */
    public function cacheable(Request $request, array $payload, int $maxAge, ?string $contentType = null): Response
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $response = new JsonResponse($json, Response::HTTP_OK, [], true);
        if (null !== $contentType) {
            $response->headers->set('Content-Type', $contentType);
        }

        return $this->publicCache($request, $response, md5($json), $maxAge);
    }

    /** The cache headers of {@see cacheable()} on a response that is not JSON (the GPX). */
    public function publicCache(Request $request, Response $response, string $etag, int $maxAge): Response
    {
        $response->setEtag($etag);
        $response->setPublic();
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setMaxAge($maxAge);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * `minLon,minLat,maxLon,maxLat` in WGS84, min below max; null when malformed.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function bbox(mixed $raw): ?array
    {
        if (!\is_string($raw)) {
            return null;
        }
        $parts = explode(',', $raw);
        if (4 !== \count($parts)) {
            return null;
        }
        $nums = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!is_numeric($part)) {
                return null;
            }
            $nums[] = (float) $part;
        }
        [$minLon, $minLat, $maxLon, $maxLat] = $nums;
        if (abs($minLon) > 180.0 || abs($maxLon) > 180.0 || abs($minLat) > 90.0 || abs($maxLat) > 90.0) {
            return null;
        }
        if ($minLon >= $maxLon || $minLat >= $maxLat) {
            return null;
        }

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * A required bbox no wider than `$maxSpan` degrees per axis, or the 400
     * that says why not.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|JsonResponse
     */
    public function requiredBbox(mixed $raw, float $maxSpan = self::BBOX_MAX_SPAN_DEG): array|JsonResponse
    {
        $bbox = $this->bbox($raw);
        if (null === $bbox) {
            return $this->error('invalid_bbox', 'bbox is required: minLon,minLat,maxLon,maxLat in WGS84, min < max');
        }
        if ($bbox[2] - $bbox[0] > $maxSpan || $bbox[3] - $bbox[1] > $maxSpan) {
            return $this->error('bbox_too_large', \sprintf('bbox may span at most %sx%s degrees', self::degrees($maxSpan), self::degrees($maxSpan)));
        }

        return $bbox;
    }

    /** `limit`, clamped to 1..LIMIT_MAX; the default when absent or not a number. */
    public function limit(mixed $raw, int $max = self::LIMIT_MAX): int
    {
        $limit = (\is_string($raw) && ctype_digit($raw)) ? (int) $raw : min(self::LIMIT_DEFAULT, $max);

        return min($max, max(1, $limit));
    }

    /**
     * An opaque cursor: the keys of the last row served, so the next page
     * starts after it (keyset paging, stable under inserts).
     *
     * @param array<string, int|string> $after
     */
    public static function cursor(array $after): string
    {
        return rtrim(strtr(base64_encode(json_encode($after, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * The keys a cursor carries; null when absent, an empty array when it is
     * not one of ours (the caller answers `invalid_cursor`).
     *
     * @return array<string, int|string>|null
     */
    public static function after(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw) || '' === $raw || \strlen($raw) > 200) {
            return [];
        }
        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        $keys = false !== $json ? json_decode($json, true) : null;
        if (!\is_array($keys) || [] === $keys) {
            return [];
        }
        $out = [];
        foreach ($keys as $k => $v) {
            if (!\is_string($k) || !(\is_int($v) || \is_string($v))) {
                return [];
            }
            $out[$k] = $v;
        }

        return $out;
    }

    private static function degrees(float $span): string
    {
        return rtrim(rtrim(number_format($span, 2, '.', ''), '0'), '.');
    }
}
