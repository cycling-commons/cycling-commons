<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\V1\ApiResponder;
use App\Api\V1\CategoryTable;
use App\Api\V1\PublicRegionsProvider;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use App\Vote\BallotRegions;
use App\Vote\BestOfResults;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Regions, what they hold, and their best-of lists.
 *
 * @see docs/specs/public-api.md §2.2
 *
 * @api
 */
final class RegionApiController extends AbstractController
{
    /** A region slug, as /regions/{slug} uses it. */
    private const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(private readonly ApiResponder $api)
    {
    }

    #[Route('/v1/regions', name: 'api_v1_regions', methods: ['GET'])]
    public function list(Request $request, PublicRegionsProvider $regions): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $query = $request->query->all();

        $country = $query['country'] ?? null;
        if (null !== $country && (!\is_string($country) || 1 !== preg_match('~^[A-Za-z]{2}$~', $country))) {
            return $this->api->error('invalid_country', 'country must be a two-letter ISO 3166-1 code, or absent for all');
        }
        $after = ApiResponder::after($query['cursor'] ?? null);
        if ([] === $after || (null !== $after && !\is_string($after['s'] ?? null))) {
            return $this->api->error('invalid_cursor', 'cursor must be the next_cursor of a previous page');
        }

        $limit = $this->api->limit($query['limit'] ?? null);
        $features = $regions->list($limit + 1, null !== $after ? (string) $after['s'] : null, null !== $country ? strtoupper($country) : null);
        $payload = [
            'type' => 'FeatureCollection',
            'features' => \array_slice($features, 0, $limit),
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ];
        if (\count($features) > $limit) {
            $payload['next_cursor'] = ApiResponder::cursor(['s' => (string) $features[$limit - 1]['id']]);
        }

        return $this->api->cacheable($request, $payload, 3600, 'application/geo+json');
    }

    #[Route('/v1/regions/{id}', name: 'api_v1_region', requirements: ['id' => self::SLUG], methods: ['GET'])]
    public function region(Request $request, string $id, PublicRegionsProvider $regions): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $region = $regions->region($id);
        if (null === $region) {
            return $this->api->notFound(\sprintf('no region %s; /v1/regions lists them', $id));
        }

        return $this->api->cacheable($request, $region + [
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ], 3600, 'application/geo+json');
    }

    /**
     * The region's current best-of lists: what riders voted best in each
     * votable category for the season under way, from the round that has
     * closed (route-domain.md §8b, §8d). Nothing while community voting is
     * off: the /best page then shows a preview with made-up tallies, which
     * is never data.
     */
    #[Route('/v1/regions/{id}/best', name: 'api_v1_region_best', requirements: ['id' => self::SLUG], methods: ['GET'])]
    public function best(Request $request, string $id, BallotRegions $ballotRegions, BestOfResults $results, SettingsProviderInterface $settings): Response
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $region = $ballotRegions->bySlug($id);
        if (null === $region) {
            return $this->api->notFound(\sprintf('no region %s; /v1/regions lists them', $id));
        }

        $live = 1 === $settings->get(SettingsRegistry::COMMUNITY_VOTING_LIVE);
        $lists = [];
        foreach ($live ? $results->standings($region) : [] as $standing) {
            $lists[] = [
                'letter' => $standing['type']->letter(),
                'category' => $standing['type']->value,
                'season' => $standing['round']->season->value,
                'year' => $standing['round']->yearLabel(),
                'ranked' => $standing['ranked'],
                'entries' => array_map(static fn (array $e): array => [
                    'id' => $e['id'],
                    'letter' => $e['letter'],
                    'name' => $e['name'],
                    'place' => $e['place'],
                    'shared' => $e['shared'],
                    'points' => $e['points'],
                    'on_map' => $e['onMap'],
                ], $standing['top']),
            ];
        }

        return $this->api->cacheable($request, [
            'region_id' => $region['slug'],
            'voting' => $live,
            'lists' => $lists,
            'licence' => 'ODbL-1.0',
            'attribution' => CategoryTable::ATTRIBUTION,
        ], 3600);
    }
}
