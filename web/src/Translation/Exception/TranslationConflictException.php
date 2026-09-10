<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
