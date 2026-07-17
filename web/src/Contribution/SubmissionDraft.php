<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\ItemType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The typed intake envelope between forms (and the future JSON API) and
 * CatalogContributionService. One DTO for all 11 item types. Per-letter
 * field shape lives in the registry (catalog-data-model.md §7), not in
 * classes.
 *
 * @api Built by ContributeController, validated by ValidatorInterface.
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
     *                                         arrays — see AttributeVocabulary.
     */
    public function __construct(
        public ItemType $type,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        #[Assert\NoSuspiciousCharacters(locales: ['en', 'fr', 'nl', 'de'])]
        // NoSuspiciousCharacters' CHECK_INVISIBLE (ICU Spoofchecker) only fires
        // on *repeated identical* nonspacing combining marks. A lone
        // zero-width/format character (e.g. U+200B ZERO WIDTH SPACE) is
        // "Common" script and passes every restriction level short of ASCII,
        // which would also reject legitimate accented titles. Verified
        // empirically against ICU 74.2. This Regex closes that specific gap
        // without touching the accented-text case. The same guard is
        // centralized in CatalogFieldConstraints for every registry-driven
        // text field; title is validated directly here because SubmissionDraft
        // is a DTO, not built via that helper.
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
    ) {
    }
}
