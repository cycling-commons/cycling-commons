<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1\Dto;

/**
 * A recommended route with its whole line (docs/specs/public-api.md §2.2):
 * `/v1/routes` lists them, `/v1/routes/{id}` adds the detail. What a rider
 * picked from a list or the server measured; never who proposed it, their
 * note, the start label they typed, votes or moderation state
 * (docs/specs/public-api-personal-data-boundary.md).
 */
final readonly class RouteLine
{
    /**
     * @param array<string, mixed>                  $geometry   decoded GeoJSON LineString
     * @param 'community'|'curated'                 $tier
     * @param array{score: int, label: string}|null $difficulty
     * @param list<string>                          $season
     * @param list<string>                          $bikeTypes
     * @param array<string, mixed>|null             $detail     only on one route: the ratings, surfaces, elevation, photos and GPX link
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $tier,
        public array $geometry,
        public int $distanceM,
        public int $ascentM,
        public ?string $regionId,
        public ?array $difficulty,
        public ?string $dominantSurface,
        public array $season,
        public array $bikeTypes,
        public ?array $detail = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toGeoJson(): array
    {
        $properties = [
            'id' => $this->id,
            'letter' => 'R',
            'name' => $this->name,
            'tier' => $this->tier,
            'distance_m' => $this->distanceM,
            'ascent_m' => $this->ascentM,
            'region_id' => $this->regionId,
            'difficulty' => $this->difficulty,
            'dominant_surface' => $this->dominantSurface,
            'season' => $this->season,
            'bike_types' => $this->bikeTypes,
        ];

        return [
            'type' => 'Feature',
            'geometry' => $this->geometry,
            'properties' => null === $this->detail ? $properties : $properties + $this->detail,
        ];
    }
}
