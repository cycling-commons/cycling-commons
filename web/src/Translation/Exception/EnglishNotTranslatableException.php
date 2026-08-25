<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * English is never proposed from the website.
 *
 * @see docs/specs/translations.md §1, §4
 *
 * @api
 */
final class EnglishNotTranslatableException extends \RuntimeException
{
}
