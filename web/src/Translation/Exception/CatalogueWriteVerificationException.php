<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * The proposed single-line edit would change more than the one key being
 * written, or would write a value that does not round-trip to what was
 * asked for.
 *
 * A hand-rolled line edit is exactly where silent corruption hides, so
 * CatalogueWriter parses the original and the candidate content into
 * arrays and diffs them before any byte reaches disk. This is thrown from
 * that in-memory check, so the file is never touched (translations.md
 * §7.4).
 *
 * @see docs/specs/translations.md §7.4
 *
 * @api
 */
final class CatalogueWriteVerificationException extends \RuntimeException
{
}
