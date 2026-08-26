<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Proposal submitted without CC BY-SA consent (no tick, and no current record).
 *
 * @see docs/specs/translations.md §4
 *
 * @api
 */
final class ConsentRequiredException extends \RuntimeException
{
}
