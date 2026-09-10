<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Api\V1\Dto;

use App\Catalog\ItemEvidence;

/**
 * Public catalogue item (docs/specs/public-api-personal-data-boundary.md).
 * No contributor, raw attributes, or moderation state.
 *
 * Carries the trust envelope (docs/specs/public-api.md §1): the grade first,
 * and behind it the receipt that produced it, a count and dates, never a
 * person. A consumer that only filters reads `grade`; one that has to defend
 * a decision reads `confirmations` and the dates.
 */
final readonly class ItemFeature
{
    /**
     * @param array<string, mixed>  $geometry decoded GeoJSON geometry
     * @param 'community'|'curated' $tier
     */
    public function __construct(
        public int $id,
        public string $letter,
        public string $name,
        public string $tier,
        public array $geometry,
        public ItemEvidence $evidence,
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
                'grade' => $this->evidence->grade,
                'custody' => $this->evidence->custody->value,
                'confirmations' => $this->evidence->confirmations,
                'last_confirmed' => $this->evidence->lastConfirmed?->format('Y-m-d'),
                'last_seen_upstream' => $this->evidence->lastSeenUpstream?->format('Y-m-d'),
                'verified_by' => $this->evidence->verifiedBy,
            ],
        ];
    }
}
