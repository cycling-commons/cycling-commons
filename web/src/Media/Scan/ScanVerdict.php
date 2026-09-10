<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Scan;

/**
 * Scanner result. `skipped` is clean-by-default with CLAMAV_REQUIRED off, not a real verdict.
 *
 * @see docs/specs/media-storage-architecture.md §3.1
 *
 * @api
 */
final readonly class ScanVerdict
{
    private function __construct(
        public bool $infected,
        public ?string $signature = null,
        public bool $skipped = false,
    ) {
    }

    public static function clean(): self
    {
        return new self(false);
    }

    public static function infected(string $signature): self
    {
        return new self(true, $signature);
    }

    public static function skipped(): self
    {
        return new self(false, null, true);
    }
}
