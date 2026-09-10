<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
