<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Scan;

/**
 * Release-gate scanner. Unavailability is not a clean verdict.
 *
 * @see docs/specs/media-storage-architecture.md §3.1
 *
 * @api
 */
interface VirusScannerInterface
{
    /**
     * @param resource|string $bytes
     *
     * @throws ScannerUnavailable when no verdict could be obtained and CLAMAV_REQUIRED
     */
    public function scan(mixed $bytes): ScanVerdict;
}
