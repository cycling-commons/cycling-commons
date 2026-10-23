<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * A region's history text cannot be approved while a link in it is not
 * plainly fine and the approving curator has not confirmed by hand that
 * they opened it and it is right. Not an error in the text: a tick the
 * curator gives on the text's own form, `/moderate/text/{id}`
 * (docs/specs/moderation-and-contribution.md §3.1c).
 *
 * @api
 */
final class TextLinksUnconfirmedException extends \RuntimeException implements DecisionRefusal
{
}
