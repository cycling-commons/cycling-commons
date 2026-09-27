<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Scan;

/**
 * Scanner result: clean or infected. No verdict is not one of them; that is
 * ScannerUnavailable, thrown.
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
}
