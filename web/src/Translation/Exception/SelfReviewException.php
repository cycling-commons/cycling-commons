<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Curator tried to decide their own translation proposal.
 *
 * @see docs/specs/translations.md §5
 *
 * @api
 */
final class SelfReviewException extends \RuntimeException
{
}
