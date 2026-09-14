<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Message;

use App\Media\PhotoPlace;

/**
 * Ask Wikidata which Commons file an item's P18 names. Scalars only.
 *
 * The continent and the place that asked ride along because a hit admits a
 * FetchCommonsPhoto straight away, and that needs to know which bucket
 * receives the bytes and which place the file is judged against.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final readonly class ResolveWikidataImage
{
    public function __construct(
        public string $qid,
        public string $continent,
        public ?string $letter = null,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {
    }

    public static function forPlace(string $qid, string $continent, PhotoPlace $place): self
    {
        return new self($qid, $continent, $place->letter, $place->lat, $place->lng);
    }

    /** `??` so a message queued before these fields existed reads as unplaced. */
    public function place(): PhotoPlace
    {
        return new PhotoPlace($this->letter ?? null, $this->lat ?? null, $this->lng ?? null);
    }
}
