<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
