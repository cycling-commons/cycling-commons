<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
