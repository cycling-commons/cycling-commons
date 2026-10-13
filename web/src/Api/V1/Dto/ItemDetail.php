<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1\Dto;

/**
 * One item in full, `/v1/items/{id}` (docs/specs/public-api.md §2.2): the
 * search feature's properties, then where it comes from, its kind, the
 * served attributes and its photos. A closed key list like every API DTO
 * (docs/specs/public-api-personal-data-boundary.md §5.2): no contributor, no
 * free text, no moderation state.
 */
final readonly class ItemDetail
{
    /**
     * @param array<string, mixed>                                                                                                                  $attributes the served attributes (App\Api\V1\PublicFields)
     * @param list<array{url: string, thumb: string|null, licence: string|null, credit: string|null, credit_url: string|null, source: string|null}> $photos
     * @param array<string, mixed>|null                                                                                                             $osm        the cached OpenStreetMap subset, only when asked for
     */
    public function __construct(
        public ItemFeature $feature,
        public string $country,
        public ?string $osmRef,
        public string $source,
        public ?string $kind,
        public array $attributes,
        public array $photos,
        public ?array $osm = null,
        public bool $hydrated = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toGeoJson(): array
    {
        $geoJson = $this->feature->toGeoJson();
        $geoJson['properties'] += [
            'country' => '' !== $this->country ? $this->country : null,
            'osm_ref' => $this->osmRef,
            'source' => $this->source,
            'kind' => $this->kind,
            // (object) so no attributes still encodes {} rather than [].
            'attributes' => (object) $this->attributes,
            'photos' => $this->photos,
        ];
        if ($this->hydrated) {
            $geoJson['properties']['osm'] = $this->osm;
        }

        return $geoJson;
    }
}
