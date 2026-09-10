<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One OpenStreetMap element's tags, by ref. The town card needs exactly one of
 * them: `wikidata`, which is how a Photon hit becomes a Wikipedia page.
 *
 * Server-side and identified, like every other third-party call we make
 * (operations.md §4b is about Photon, which stays in the browser; this is one
 * request per town and language, ever, cached in town_summary).
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @api
 */
final readonly class OsmElementApi
{
    public const array TYPES = ['node', 'way', 'relation'];

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(APP_COMMONS_USER_AGENT)%')]
        private string $userAgent = '',
    ) {
    }

    /**
     * The element's tags, or null when OpenStreetMap no longer has it.
     *
     * @return array<string, string>|null
     *
     * @throws TownSourceUnavailable
     */
    public function tags(string $type, int $id): ?array
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Not an OSM element type: '.$type);
        }
        try {
            $response = $this->http->request('GET', sprintf('https://api.openstreetmap.org/api/0.6/%s/%d.json', $type, $id), [
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            $status = $response->getStatusCode();
            if (404 === $status || 410 === $status) {
                return null;
            }
            if (200 !== $status) {
                throw new TownSourceUnavailable('osm_http_'.$status);
            }
            /** @var array{elements?: list<array{tags?: array<string, mixed>}>} $data */
            $data = $response->toArray();
        } catch (TownSourceUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TownSourceUnavailable('osm: '.$e->getMessage());
        }

        $tags = [];
        foreach ($data['elements'][0]['tags'] ?? [] as $k => $v) {
            if (\is_string($v)) {
                $tags[(string) $k] = $v;
            }
        }

        return $tags;
    }
}
