<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Missing, stale, or foreign consent. Every ambiguity is this.
 *
 * @see docs/specs/photo-uploads.md §4
 *
 * @api
 */
final class ConsentMissing extends \RuntimeException
{
}
