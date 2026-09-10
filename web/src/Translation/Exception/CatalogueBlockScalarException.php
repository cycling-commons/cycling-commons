<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * The requested message key resolves to a block scalar (`key: |` or
 * `key: >`) rather than a single-line quoted value.
 *
 * CatalogueWriter edits exactly one line. A block scalar's value spans
 * several lines with its own indentation and chomping rules, so there is
 * no single line to replace; rewriting it correctly would need the same
 * parse-and-dump round trip translations.md §7.4 rejects for the whole
 * file. `export.readme` is the one such value in every catalogue today.
 *
 * @see docs/specs/translations.md §7.4
 *
 * @api
 */
final class CatalogueBlockScalarException extends \RuntimeException
{
}
