<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * A needs-info decision arrived without a note.
 *
 * Distinct from the other decision failures because it is the curator's own
 * slip, not a state conflict: the submission is perfectly decidable, the
 * question is simply missing. The rider would otherwise be told "a curator
 * needs more information" with nothing to answer, while their submission
 * leaves the map queue until they answer it — so it is refused rather than
 * recorded.
 *
 * @see docs/specs/moderation-and-contribution.md §5.3
 */
final class MissingQuestionException extends \InvalidArgumentException
{
}
