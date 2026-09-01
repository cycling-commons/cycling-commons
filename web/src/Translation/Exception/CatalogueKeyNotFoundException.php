<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * The requested message key has no scalar value in the target catalogue
 * file: either it does not appear at all, or it names a mapping (a
 * section, with children of its own) rather than a leaf.
 *
 * A value-write must never add a key, so a miss here is always a refusal,
 * never a fallback to appending one (translations.md §7.4).
 *
 * @see docs/specs/translations.md §7.4
 *
 * @api
 */
final class CatalogueKeyNotFoundException extends \RuntimeException
{
}
