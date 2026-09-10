<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Append-only lifecycle events. Purged only with Trash and orphan collection.
 *
 * @see docs/specs/photo-uploads.md §5b, §6
 *
 * @api
 */
final class MediaAction
{
    public const string Uploaded = 'uploaded';
    /** Worker released public objects. @see docs/specs/media-storage-architecture.md §3 */
    public const string Released = 'released';
    /** Scanner found something; object deleted in the same pass. */
    public const string ScanInfected = 'scan_infected';
    /** Decode failed or decompression bomb. @see docs/specs/media-storage-architecture.md §3.2 */
    public const string ScanUnreadable = 'scan_unreadable';
    public const string Claimed = 'claimed';
    public const string Approved = 'approved';
    public const string Rejected = 'rejected';
    public const string CreditAnonymized = 'credit_anonymized';
    public const string ObjectsDeleted = 'objects_deleted';
    /** Uploader asked for it to come down. @see docs/specs/photo-uploads.md §6b */
    public const string TakedownRequested = 'takedown_requested';
    /** Third-party report. @see docs/specs/photo-uploads.md §6c */
    public const string ThirdPartyReported = 'third_party_reported';
    public const string TakedownGranted = 'takedown_granted';
    public const string TakedownDeclined = 'takedown_declined';
    /** Abusive-report undo; does not close the category. @see docs/specs/photo-uploads.md §6c */
    public const string TakedownDismissedAsAbuse = 'takedown_dismissed_as_abuse';
    /** Legal hold on / off. @see docs/specs/photo-uploads.md §6d */
    public const string Escalated = 'escalated';
    public const string EscalationReleased = 'escalation_released';
}
