<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * A decision that restricts what a rider added arrived without the words its
 * statement of reasons needs: a rejection without a note, Trash as abuse
 * without the facts, a ground missing.
 *
 * Its message is the catalogue key the desk shows.
 *
 * @see docs/specs/content-reports.md §7
 */
final class MissingReasonException extends \InvalidArgumentException
{
}
