<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * English is proposed only by a user whose reachable roles include
 * ROLE_CURATOR.
 *
 * @see docs/specs/translations.md §1, §4.2
 *
 * @api
 */
final class EnglishNotTranslatableException extends \RuntimeException
{
}
