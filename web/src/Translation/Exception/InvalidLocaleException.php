<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Locale is not one of the translatable non-English locales.
 *
 * @see docs/specs/translations.md §1
 *
 * @api
 */
final class InvalidLocaleException extends \InvalidArgumentException
{
}
