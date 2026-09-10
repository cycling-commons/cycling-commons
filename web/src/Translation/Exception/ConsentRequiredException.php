<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
