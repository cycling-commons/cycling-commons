<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * Curator decision named a proposal id that does not exist.
 *
 * @see docs/specs/translations.md §5
 *
 * @api
 */
final class UnknownProposalException extends \InvalidArgumentException
{
}
