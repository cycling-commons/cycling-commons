<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * A needs-info decision arrived without a note.
 *
 * @see docs/specs/moderation-and-contribution.md §5.3
 */
final class MissingQuestionException extends \InvalidArgumentException
{
}
