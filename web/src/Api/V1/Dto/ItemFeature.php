<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Api\V1\Dto;

/**
 * The public shape of one catalogue item
 * (public-api-personal-data-boundary.md): a dedicated DTO, never an entity or
 * a raw provider row, so the serialization boundary is this constructor's
 * parameter list. Contributor identity, raw attributes, and moderation state
 * have no field here and therefore no path into a /v1 response.
 */
final readonly class ItemFeature
{
    /**
     * @param array<string, mixed> $geometry decoded GeoJSON geometry
     * @param 'community'|'curated' $tier
     */
    public function __construct(
        public int $id,
        public string $letter,
        public string $name,
        public string $tier,
        public array $geometry,
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
                'letter' => $this->letter,
                'name' => $this->name,
                'tier' => $this->tier,
            ],
        ];
    }
}
