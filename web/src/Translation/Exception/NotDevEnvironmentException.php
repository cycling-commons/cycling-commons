<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * CatalogueWriter was asked to write outside the dev kernel environment.
 *
 * The DeepL dev tool bypasses the proposal and approval flow entirely
 * because a developer, on their own machine, reviewing a diff before it
 * reaches git, is not a rider using the live editor. That structural
 * protection only holds while the write path is unreachable off dev, so
 * this check comes before every other check in CatalogueWriter::write().
 *
 * @see docs/specs/translations.md §7.1, §7.3
 *
 * @api
 */
final class NotDevEnvironmentException extends \RuntimeException
{
}
