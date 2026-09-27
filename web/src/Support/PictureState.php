<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * Where a picture a person sent us stands.
 *
 * `pending`: the raw bytes are held, nothing has looked at them yet, and they
 * are never served. `ready`: the worker scanned them and drew them again; the
 * stored bytes are that drawing. `refused`: the worker would not keep them
 * (infected, unreadable, too large); the bytes are gone and only the reason
 * stays.
 *
 * @see docs/specs/contact-and-support.md §6
 *
 * @api
 */
enum PictureState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Refused = 'refused';
}
