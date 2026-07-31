<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * The lifecycle state of a stored photo. Always the CURRENT state — how it got
 * here lives in media_moderation_event (docs/specs/photo-uploads.md §5b).
 *
 * @api Persisted as media_upload.status.
 */
enum MediaStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
