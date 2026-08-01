<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Who asked for a photo to come down (docs/specs/photo-uploads.md §6b/§6c).
 *
 * The discriminator is what keeps "one way to moderate" true while the two
 * routes behave differently at the door: the uploader owns the row, so their
 * request withholds on the spot; a stranger's request queues and changes
 * nothing, because instant withholding on an anonymous POST would be a
 * heckler's veto over the whole map.
 *
 * @api Written by MediaTakedownService, read by the moderation desk.
 */
final class MediaTakedownSource
{
    public const string Uploader = 'uploader';
    public const string ThirdParty = 'third_party';
}
