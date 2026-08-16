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
    /**
     * The worker scanned the quarantined bytes and physically released the
     * derivatives (docs/specs/media-storage-architecture.md §3). The note
     * carries the verdict - "clean", or "skipped: no scanner" on a
     * contributor stack with CLAMAV_REQUIRED off, because a clean-by-default
     * answer must never be readable afterwards as a real one.
     */
    public const string Released = 'released';
    /**
     * The scanner found something. The object was deleted in the same pass,
     * the note names the signature, and the rider was told.
     */
    public const string ScanInfected = 'scan_infected';
    /**
     * The bytes could not be turned into a photo - the decode failed, or the
     * file was a decompression bomb the endpoint deliberately no longer looks
     * at (docs/specs/media-storage-architecture.md §3.2). Not a moderation
     * decision and not an infection; the note carries the reason code.
     */
    public const string ScanUnreadable = 'scan_unreadable';
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
    /**
     * An admin restored a photo that an abusive report had withheld
     * (docs/specs/photo-uploads.md §6c). Deliberately NOT a decline: a decline
     * is a judgement on a claim and closes that category forever, which after
     * a flood would immunise the attacked photos against the next genuine
     * report. This says only "that report was not real", and leaves the door
     * open.
     */
    public const string TakedownDismissedAsAbuse = 'takedown_dismissed_as_abuse';
    /**
     * A curator escalated suspected illegal content, putting the row under
     * legal hold (docs/specs/photo-uploads.md §6d), and an admin later
     * released it. Both are append-only like everything here — and here the
     * log is not just good practice, it is the account of our handling we
     * would have to give.
     */
    public const string Escalated = 'escalated';
    public const string EscalationReleased = 'escalation_released';
}
