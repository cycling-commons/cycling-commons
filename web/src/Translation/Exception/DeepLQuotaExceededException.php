<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * The configured key's DeepL character quota is exhausted (HTTP 456).
 *
 * @see docs/specs/translations.md §7.1
 *
 * @api
 */
final class DeepLQuotaExceededException extends \RuntimeException
{
}
