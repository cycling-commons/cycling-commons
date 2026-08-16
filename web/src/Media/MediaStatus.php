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
    /**
     * Received, quarantined, not yet scanned
     * (docs/specs/media-storage-architecture.md §3). Nothing is published: the
     * raw bytes sit in the private bucket and the row has no revision, so
     * there is no URL to build and no object for anyone to reach. The worker
     * moves it to Pending on a clean verdict, or to Rejected on an infected
     * one.
     */
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
