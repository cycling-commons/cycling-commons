<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Scan;

/**
 * The release gate's scanner (media plan task 3).
 *
 * Three outcomes, deliberately not two: clean and infected are VERDICTS,
 * while "the scanner is not answering" is neither and must never be folded
 * into clean - a fail-open scanner is indistinguishable from a working one
 * until the day it matters. Callers decide what unavailability means for
 * them; the release handler throws so Messenger retries and the object
 * stays quarantined.
 *
 * @api Implemented by ClamAvScanner; consumed by the release handler.
 */
interface VirusScannerInterface
{
    /**
     * @param resource|string $bytes the raw object, as a stream or string
     *
     * @throws ScannerUnavailable when no verdict could be obtained and the
     *                            environment requires one (CLAMAV_REQUIRED)
     */
    public function scan(mixed $bytes): ScanVerdict;
}
