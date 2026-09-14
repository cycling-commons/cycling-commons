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
 * `Declined` is a verdict about a place, not the file: PhotoValidator refused
 * it for the place that asked (a scenic view whose pin is far from the camera,
 * or whose camera is unknown), so no bytes were downloaded, or the stored
 * copy was removed. The row keeps the credit, licence and camera Commons
 * reported, so the same refusal is known without asking again, and a place
 * that may show the file reopens it (CommonsPhotoAdmission::admit()).
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
    case Declined = 'declined';
}
