<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * A town or region text cannot be approved while the curator has not said
 * whether the Wikipedia credit stays (owner 2026-10-01).
 *
 * Not an error in the rider's text: a decision the curator makes on the
 * text's own form, `/moderate/text/{id}`
 * (docs/specs/moderation-and-contribution.md §3.1b).
 *
 * @api
 */
final class TextCreditUndecidedException extends \RuntimeException
{
}
