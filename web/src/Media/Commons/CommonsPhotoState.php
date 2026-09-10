<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Commons;

/**
 * Where one cached Commons file has got to.
 *
 * `Unusable` and `Failed` are deliberately different. Unusable is a verdict
 * about the file itself, for instance a licence we may not republish, and it is
 * terminal: asking again would get the same answer. Failed is about us, for
 * instance Commons timed out, and a later run may retry it.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
enum CommonsPhotoState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Unusable = 'unusable';
    case Failed = 'failed';
}
