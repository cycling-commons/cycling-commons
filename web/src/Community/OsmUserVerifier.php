<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Best-effort OSM handle lookup. Unreachable OSM still lets the application submit.
 *
 * @see docs/specs/moderation-and-contribution.md §11
 *
 * @api
 */
final class OsmUserVerifier
{
    private const string ENDPOINT = 'https://api.openstreetmap.org/api/0.6/changesets';
    private const int TIMEOUT_SECONDS = 4;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** Charset-checked before the value is put in a URL. */
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

        if ($changesetCount >= 100) {
            $changesetCount = $this->resolvePaginationCap($body, $changesetCount);
        }

        return new OsmVerification(true, true, $changesetCount);
    }

    /**
     * When the list is capped at 100, fetch the user's true changeset count.
     *
     * @return int the resolved count, or the page count if resolution fails
     */
    private function resolvePaginationCap(string $changesetsXml, int $pageCount): int
    {
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

            if (preg_match('/<changesets\s+count="(\d+)"/', $body, $matches)) {
                return (int) $matches[1];
            }
        } catch (ExceptionInterface) {
        }

        return $pageCount;
    }
}
