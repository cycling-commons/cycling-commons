<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Message;

/**
 * Ask Wikidata which Commons file an item's P18 names. Scalars only.
 *
 * The continent rides along because a hit dispatches a FetchCommonsPhoto
 * straight away, and that needs to know which bucket receives the bytes.
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
    ) {
    }
}
