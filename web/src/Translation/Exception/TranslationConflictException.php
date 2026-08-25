<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Concurrent submit hit the open-proposal unique index.
 *
 * @see docs/specs/translations.md §4
 *
 * @api
 */
final class TranslationConflictException extends \RuntimeException
{
}
