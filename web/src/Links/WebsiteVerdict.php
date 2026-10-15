<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Links;

/**
 * One address, one of four words (docs/specs/website-liveness.md section 1):
 * live, parked, dead or unknown, from the final status and the page, or from
 * the transport failure when there was no answer. The key of a verdict is
 * the address's host, lower-cased, port dropped, `www.` kept as written.
 *
 * @api
 */
final class WebsiteVerdict
{
    public const string LIVE = 'live';
    public const string PARKED = 'parked';
    public const string DEAD = 'dead';
    public const string UNKNOWN = 'unknown';

    /**
     * Phrases a real site about a place does not carry: a domain for sale, a
     * hosting provider's "connect your domain" page, a registrar's placeholder.
     * Matched case-insensitively in the title and the first 64 KB. A false
     * "parked" costs a rider their link, so the list stays short and literal.
     */
    public const array PARKED_SIGNATURES = [
        'connectyourdomain',
        'domain error | wix',
        'this domain is parked',
        'parked domain',
        'domain parking',
        'buy this domain',
        'domain is for sale',
        'domain for sale',
        'this domain may be for sale',
        'sedoparking',
        'parkingcrew',
        'afternic.com',
        'hugedomains',
        'dan.com/buy-domain',
        'domein te koop',
        'domaine à vendre',
        'domain zu verkaufen',
        'dominio en venta',
    ];

    /** Days until a verdict is taken again (docs/specs/website-liveness.md section 1). */
    private const array RECHECK_DAYS = [self::LIVE => 30, self::PARKED => 14, self::DEAD => 14, self::UNKNOWN => 3];

    /** Transport failures that say the site is not there, as opposed to ones that may be ours. */
    private const array DEAD_TRANSPORT = ['could not resolve', 'name or service not known', 'connection refused', 'ssl', 'tls', 'certificate', 'no route to host'];

    private function __construct(
        public readonly string $verdict,
        public readonly ?int $status,
        public readonly string $reason,
    ) {
    }

    /** The verdict of an answered fetch: the final status and the page. */
    public static function of(int $status, string $body): self
    {
        $haystack = mb_strtolower(mb_substr($body, 0, 65536));
        foreach (self::PARKED_SIGNATURES as $signature) {
            if (str_contains($haystack, mb_strtolower($signature))) {
                return new self(self::PARKED, $status, $signature);
            }
        }
        if ($status >= 200 && $status < 400) {
            return new self(self::LIVE, $status, '');
        }

        return new self(self::DEAD, $status, 'http '.$status);
    }

    /** The verdict of a fetch that got no answer: the transport's own words decide dead or unknown. */
    public static function failed(string $error): self
    {
        $lower = strtolower($error);
        foreach (self::DEAD_TRANSPORT as $needle) {
            if (str_contains($lower, $needle)) {
                return new self(self::DEAD, null, mb_substr($error, 0, 40));
            }
        }

        return new self(self::UNKNOWN, null, mb_substr($error, 0, 40));
    }

    /** An address that is not one: nothing to fetch, nothing there. */
    public static function unfetchable(): self
    {
        return new self(self::DEAD, null, 'no host');
    }

    /** Whether a verdict takes the website off what a rider sees and refuses it at intake: dead or parked. */
    public static function hides(?string $verdict): bool
    {
        return self::DEAD === $verdict || self::PARKED === $verdict;
    }

    public static function daysUntilRecheck(string $verdict): int
    {
        return self::RECHECK_DAYS[$verdict] ?? self::RECHECK_DAYS[self::UNKNOWN];
    }

    /** The host of an http(s) address, lower-cased, port dropped; null when it is not one. */
    public static function hostOf(string $url): ?string
    {
        if (1 !== preg_match('~^https?://~i', $url)) {
            return null;
        }
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return null;
        }

        return strtolower($host);
    }
}
