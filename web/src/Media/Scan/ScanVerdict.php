<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Scan;

/**
 * What the scanner said. `signature` names the detection on an infected
 * verdict (for the event log), and `skipped` marks a clean-BY-DEFAULT answer
 * from an environment with no scanner and CLAMAV_REQUIRED off - recorded so
 * a "clean" in dev logs never masquerades as a real verdict.
 *
 * @api Returned by VirusScannerInterface::scan().
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
