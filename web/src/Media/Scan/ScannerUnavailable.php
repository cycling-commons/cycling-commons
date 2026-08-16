<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Scan;

/**
 * No verdict could be obtained and this environment requires one.
 *
 * A RuntimeException on purpose: the release handler lets it propagate so
 * Messenger retries with backoff and the object stays quarantined - a
 * scanner blip must delay a rider's photo, never reject it and never
 * release it unscanned.
 *
 * @api Thrown by scanner implementations.
 */
final class ScannerUnavailable extends \RuntimeException
{
}
