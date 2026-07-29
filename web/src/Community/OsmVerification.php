<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

/**
 * @see OsmUserVerifier
 */
final readonly class OsmVerification
{
    public function __construct(
        public bool $reachable,
        public bool $exists,
        public ?int $changesets,
    ) {
    }

    public static function unreachable(): self
    {
        return new self(false, false, null);
    }
}
