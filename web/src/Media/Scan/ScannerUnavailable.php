<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Scan;

/**
 * No verdict and this environment requires one. Propagate so Messenger retries.
 *
 * @see docs/specs/media-storage-architecture.md §3.1
 *
 * @api
 */
final class ScannerUnavailable extends \RuntimeException
{
}
