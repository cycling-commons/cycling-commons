<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
