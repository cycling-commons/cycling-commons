<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Empty (or whitespace-only) proposed translation value.
 *
 * @see docs/specs/translations.md §4
 *
 * @api
 */
final class EmptyTranslationException extends \RuntimeException
{
}
