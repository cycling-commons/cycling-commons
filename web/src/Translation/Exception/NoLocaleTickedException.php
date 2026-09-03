<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * The dev catalogue form was submitted with every per-locale tick box off.
 *
 * Not a validation error on any one field: each field on its own is fine,
 * and the form is asking which of them to write. Silently redirecting with
 * a success flash would claim a write that never happened, which is the one
 * thing this form must never do (translations.md §7.3).
 */
final class NoLocaleTickedException extends \RuntimeException
{
}
