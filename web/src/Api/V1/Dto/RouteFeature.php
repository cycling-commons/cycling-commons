<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1\Dto;

/**
 * A recommended route as a search hit (docs/specs/public-api.md §2.2): one
 * point on the route, so a list of hits stays small; the line itself belongs
 * to the route's own endpoint. No proposer, votes or moderation state
 * (docs/specs/public-api-personal-data-boundary.md).
 */
final readonly class RouteFeature
{
    /**
     * @param array<string, mixed>  $geometry decoded GeoJSON Point on the route
     * @param 'community'|'curated' $tier
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $tier,
        public array $geometry,
        public int $distanceM,
        public int $ascentM,
        // The region's public slug (/v1/regions), null for a route outside every region.
        public ?string $regionId,
    ) {
    }

    /** @return array<string, mixed> */
    public function toGeoJson(): array
    {
        return [
            'type' => 'Feature',
            'geometry' => $this->geometry,
            'properties' => [
                'id' => $this->id,
                'letter' => 'R',
                'name' => $this->name,
                'tier' => $this->tier,
                'distance_m' => $this->distanceM,
                'ascent_m' => $this->ascentM,
                'region_id' => $this->regionId,
            ],
        ];
    }
}
