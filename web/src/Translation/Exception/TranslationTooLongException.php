<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Proposed value longer than TranslationLimits::PROPOSED_VALUE_MAX bytes.
 *
 * @see docs/specs/translations.md §4
 *
 * @api
 */
final class TranslationTooLongException extends \InvalidArgumentException
{
}
