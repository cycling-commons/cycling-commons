<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Message;

use App\Media\PhotoPlace;

/**
 * Fetch one Wikimedia Commons file into our own storage. Scalars only.
 *
 * The continent decides which bucket receives it, exactly as a rider's upload
 * does (photo-uploads.md §2). It is the continent of the POI that asked first;
 * the row then records the full bucket name, so the object stays addressable
 * whatever the configuration does later.
 *
 * The place that asked (letter and pin) rides along, because PhotoValidator
 * decides before the download whether that place may show the file: a scenic
 * view does not download a file whose camera stood elsewhere. No letter means
 * no place, as for a town card or a re-stamp (photo-uploads.md §5h).
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final readonly class FetchCommonsPhoto
{
    public function __construct(
        public string $file,
        public string $continent,
        public ?string $letter = null,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {
    }

    public static function forPlace(string $file, string $continent, PhotoPlace $place): self
    {
        return new self($file, $continent, $place->letter, $place->lat, $place->lng);
    }

    /** `??` so a message queued before these fields existed reads as unplaced. */
    public function place(): PhotoPlace
    {
        return new PhotoPlace($this->letter ?? null, $this->lat ?? null, $this->lng ?? null);
    }
}
