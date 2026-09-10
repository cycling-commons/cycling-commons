<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
