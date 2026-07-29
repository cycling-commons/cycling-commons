<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optional evidence: a public, checkable track record of exactly the kind of
 * work being volunteered for
 * (2026-07-29-country-requests-and-curator-signup-design.md §6).
 *
 * Best-effort by design. If OSM is slow, rate-limits us or is down, the
 * application still submits and the reviewer sees "unverified" — the same
 * silent-degradation rule the coverage manifest and the Photon geocoder follow
 * (coverage-provider.md §6). A high changeset count is not a qualification and
 * zero is not a disqualification; it is one line of evidence among three.
 */
final class OsmUserVerifier
{
    private const string ENDPOINT = 'https://api.openstreetmap.org/api/0.6/changesets';
    private const int TIMEOUT_SECONDS = 4;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * Charset-checked BEFORE the value is ever put in a URL. OSM display names
     * allow letters, digits, spaces and a small punctuation set; anything else
     * is a malformed input, not a lookup.
     */
    public function isWellFormed(string $username): bool
    {
        $u = trim($username);

        return '' !== $u
            && mb_strlen($u) <= 255
            && 1 === preg_match('/^[\p{L}\p{N} _.\-]+$/u', $u);
    }

    public function verify(string $username): OsmVerification
    {
        if (!$this->isWellFormed($username)) {
            return OsmVerification::unreachable();
        }

        try {
            $response = $this->http->request('GET', self::ENDPOINT, [
                'query' => ['display_name' => trim($username)],
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/xml'],
            ]);
            $status = $response->getStatusCode();

            if (404 === $status) {
                return new OsmVerification(true, false, null);
            }
            if (200 !== $status) {
                return OsmVerification::unreachable();
            }

            $body = $response->getContent();
        } catch (ExceptionInterface) {
            return OsmVerification::unreachable();
        }

        $changesetCount = substr_count($body, '<changeset');

        // If we're at the pagination cap (100), attempt to get the true count
        if ($changesetCount >= 100) {
            $changesetCount = $this->resolvePaginationCap($body, $changesetCount);
        }

        return new OsmVerification(true, true, $changesetCount);
    }

    /**
     * Best-effort resolution of the 100-changeset pagination cap.
     * Extracts the uid from the first changeset and fetches the user's
     * true changeset count from /user/{uid}.
     *
     * @return int the resolved count, or the page count if resolution fails
     */
    private function resolvePaginationCap(string $changesetsXml, int $pageCount): int
    {
        // Extract uid from the first changeset's uid attribute
        if (!preg_match('/<changeset[^>]+uid="(\d+)"/', $changesetsXml, $matches)) {
            return $pageCount;
        }

        $uid = $matches[1];

        try {
            $response = $this->http->request('GET', "https://api.openstreetmap.org/api/0.6/user/{$uid}", [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/xml'],
            ]);

            if (200 !== $response->getStatusCode()) {
                return $pageCount;
            }

            $body = $response->getContent();

            // Extract count attribute from <changesets count="N"/>
            if (preg_match('/<changesets\s+count="(\d+)"/', $body, $matches)) {
                return (int) $matches[1];
            }
        } catch (ExceptionInterface) {
            // Fall back to page count silently; do not degrade reachable/exists
        }

        return $pageCount;
    }
}
