<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Who asked: uploader withholds on the spot; third_party queues.
 *
 * @see docs/specs/photo-uploads.md §6b, §6c
 *
 * @api
 */
final class MediaTakedownSource
{
    public const string Uploader = 'uploader';
    public const string ThirdParty = 'third_party';
}
