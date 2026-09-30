<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The short links the project's social profiles carry: `cyclingcommons.org/m`
 * on Mastodon, `/bs` on Bluesky, and so on. Each one sends the visitor to the
 * home page with `utm_source=<platform>&utm_medium=social`, which Umami reads
 * by itself, so a visit is counted against the profile it came from.
 *
 * The target is the bare `/`, never a locale prefix: the home page picks the
 * language as it does for any other visitor. The canonical and the hreflang
 * alternates drop the query string, so a search engine folds the tagged URL
 * into `/`.
 *
 * 302, not 301: a browser remembers a permanent redirect for good, which
 * would freeze the target (and its parameters) in every browser that has
 * followed one. A shared cache may still hold the answer for an hour.
 *
 * @see docs/specs/security-architecture.md §2.2
 *
 * @api
 */
final class SocialLinkController
{
    /** Short path => the `utm_source` it reports. */
    public const array LINKS = [
        'm' => 'mastodon',
        'bs' => 'bluesky',
        'li' => 'linkedin',
        'ig' => 'instagram',
        'yt' => 'youtube',
        'r' => 'reddit',
    ];

    /** The keys of {@see self::LINKS}; a test holds the two together. */
    public const string CODE_PATTERN = 'm|bs|li|ig|yt|r';

    private const int MAX_AGE = 3600;

    #[Route('/{code}', name: 'social_link', requirements: ['code' => self::CODE_PATTERN], methods: ['GET', 'HEAD'], priority: 10)]
    public function __invoke(string $code): RedirectResponse
    {
        $response = new RedirectResponse('/?'.http_build_query([
            'utm_source' => self::LINKS[$code],
            'utm_medium' => 'social',
        ]), 302);
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        return $response;
    }
}
