<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Current lifecycle state; history lives in media_moderation_event.
 *
 * @see docs/specs/photo-uploads.md §5b
 *
 * @api
 */
enum MediaStatus: string
{
    /** Quarantined, unpublished. @see docs/specs/media-storage-architecture.md §3 */
    case PendingScan = 'pending_scan';
    /** Scanned, published, awaiting a curator. */
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Nothing has been published for this photo yet. */
    public function isQuarantined(): bool
    {
        return self::PendingScan === $this;
    }
}
