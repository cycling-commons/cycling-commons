<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Proposal target is missing or marked absent in the catalogue projection.
 *
 * @see docs/specs/translations.md §3.1, §4
 *
 * @api
 */
final class KeyNotFoundException extends \RuntimeException
{
}
