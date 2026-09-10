<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Message;

/**
 * Fetch one Wikimedia Commons file into our own storage. Scalars only.
 *
 * The continent decides which bucket receives it, exactly as a rider's upload
 * does (photo-uploads.md §2). It is the continent of the POI that asked first;
 * the row then records the full bucket name, so the object stays addressable
 * whatever the configuration does later.
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
    ) {
    }
}
