<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town\Message;

/**
 * Fill one town_summary row: OpenStreetMap ref to Wikidata id, to the Wikipedia
 * extract in the reader's language, to the cycling races Wikidata ties to the
 * town. Scalars only. The continent rides along for the photo that follows.
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @api
 */
final readonly class ResolveTownSummary
{
    public function __construct(
        public string $osmRef,
        public string $lang,
        public ?string $continent,
    ) {
    }
}
