<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One OpenStreetMap element's tags, by ref. The town card needs exactly one of
 * them: `wikidata`, which is how a Photon hit becomes a Wikipedia page. And the
 * element's point, which files the town's texts in a region.
 *
 * Server-side and identified, like every other third-party call we make
 * (operations.md §4b is about Photon, which stays in the browser; this is one
 * request per town and language, ever, cached in town_summary).
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @api
 */
final readonly class OsmElementApi implements TownPointSource
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
        $data = $this->fetch($type, $id, '');
        if (null === $data) {
            return null;
        }

        $tags = [];
        foreach ($data[0]['tags'] ?? [] as $k => $v) {
            if (\is_string($v)) {
                $tags[(string) $k] = $v;
            }
        }

        return $tags;
    }

    /**
     * A node is its own point. A way is the mean of its nodes. A relation is
     * its `admin_centre` or `label` node when it names one, as a boundary
     * relation for a town usually does, and otherwise the mean of every node
     * in it.
     */
    #[\Override]
    public function point(string $type, int $id): ?array
    {
        if ('node' === $type) {
            return self::mean($this->fetch('node', $id, '') ?? []);
        }
        if ('relation' === $type) {
            $members = $this->fetch('relation', $id, '')[0]['members'] ?? null;
            if (null === $members) {
                return null;
            }
            foreach (['admin_centre', 'label'] as $role) {
                foreach ($members as $member) {
                    if ('node' === ($member['type'] ?? null) && $role === ($member['role'] ?? null) && \is_int($member['ref'] ?? null)
                        && null !== ($centre = $this->point('node', $member['ref']))) {
                        return $centre;
                    }
                }
            }
        }

        return self::mean($this->fetch($type, $id, '/full') ?? []);
    }

    /**
     * The `elements` of one API read, or null when the element is gone.
     *
     * @return list<array<string, mixed>>|null
     *
     * @throws TownSourceUnavailable
     */
    private function fetch(string $type, int $id, string $suffix): ?array
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Not an OSM element type: '.$type);
        }
        try {
            $response = $this->http->request('GET', sprintf('https://api.openstreetmap.org/api/0.6/%s/%d%s.json', $type, $id, $suffix), [
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
            /** @var array{elements?: list<array<string, mixed>>} $data */
            $data = $response->toArray();
        } catch (TownSourceUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TownSourceUnavailable('osm: '.$e->getMessage());
        }

        return $data['elements'] ?? [];
    }

    /**
     * The mean of the nodes among some elements; null when none has a position.
     *
     * @param list<array<string, mixed>> $elements
     *
     * @return array{lat: float, lng: float}|null
     */
    private static function mean(array $elements): ?array
    {
        $lat = 0.0;
        $lng = 0.0;
        $n = 0;
        foreach ($elements as $e) {
            if ('node' === ($e['type'] ?? null) && is_numeric($e['lat'] ?? null) && is_numeric($e['lon'] ?? null)) {
                $lat += (float) $e['lat'];
                $lng += (float) $e['lon'];
                ++$n;
            }
        }

        return 0 === $n ? null : ['lat' => $lat / $n, 'lng' => $lng / $n];
    }
}
