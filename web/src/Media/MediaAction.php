<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * The closed set of lifecycle transitions recorded in media_moderation_event
 * (docs/specs/photo-uploads.md §5b). Modelled on the item-side change_history
 * idiom: append-only, never updated, never deleted — except by the two purges
 * whose whole point is that nothing survives (Trash and orphan collection,
 * docs/specs/photo-uploads.md §6).
 *
 * @api Written by every service that changes a MediaUpload.
 */
final class MediaAction
{
    public const string Uploaded = 'uploaded';
    public const string Claimed = 'claimed';
    public const string Approved = 'approved';
    public const string Rejected = 'rejected';
    public const string CreditAnonymized = 'credit_anonymized';
    public const string ObjectsDeleted = 'objects_deleted';
    /** The uploader asked for it to come down (docs/specs/photo-uploads.md §6b). */
    public const string TakedownRequested = 'takedown_requested';
    /**
     * A third party reported it (docs/specs/photo-uploads.md §6c). The note
     * carries the category, with an "(auto-withheld)" suffix when the
     * intimate-imagery/child lever fired — the log is the audit trail for
     * every use of that lever.
     */
    public const string ThirdPartyReported = 'third_party_reported';
    public const string TakedownGranted = 'takedown_granted';
    public const string TakedownDeclined = 'takedown_declined';
}
