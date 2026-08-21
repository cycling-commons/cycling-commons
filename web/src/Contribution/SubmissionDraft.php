<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\ItemSource;
use App\Catalog\ItemType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Typed intake envelope. Per-letter field shape lives in the registry
 * (docs/specs/catalog-data-model.md §7).
 *
 * @api
 */
#[ValidAttributes]
final readonly class SubmissionDraft
{
    /**
     * @param array<string, mixed> $attributes registry-keyed field values;
     *                                         mostly scalars, but a few
     *                                         keys (e.g. climb `route`/
     *                                         `grad`/`steep`, `photos`,
     *                                         `record`) are structured
     *                                         arrays. See AttributeVocabulary.
     */
    public function __construct(
        public ItemType $type,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        #[Assert\NoSuspiciousCharacters(locales: ['en', 'fr', 'nl', 'de', 'es'])]
        // CHECK_INVISIBLE misses a lone Cf character; this Regex closes that gap.
        #[Assert\Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters')]
        public string $title,
        #[Assert\Range(min: -90, max: 90)]
        public float $lat,
        #[Assert\Range(min: -180, max: 180)]
        public float $lng,
        public array $attributes,
        public ?int $itemId = null,
        #[Assert\Length(max: 2000)]
        public ?string $note = null,
        /**
         * Materialize-on-edit OSM ref (docs/specs/osm-data-architecture.md §6).
         */
        #[Assert\Regex(pattern: '~^(node|way)/\d{1,16}$~')]
        public ?string $osmRef = null,
        /* Intake channel; null keeps osm-ref → osm, else user. */
        public ?ItemSource $source = null,
    ) {
    }
}
