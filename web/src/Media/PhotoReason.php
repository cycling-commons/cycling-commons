<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Why PhotoValidator did not answer `show`.
 *
 * The value is what a refusal records (`commons_photo.failed_reason`) and what
 * a command prints, so it is stable.
 *
 * @see docs/specs/photo-uploads.md §5h
 *
 * @api
 */
enum PhotoReason: string
{
    /** Under legal hold (photo-uploads.md §6d). */
    case LegalHold = 'legal_hold';

    /** Commons marks the file non-free. */
    case NonFree = 'non_free';

    /** Commons lists a restriction on the file (trademark, personality rights and the like). */
    case Restricted = 'restricted';

    /** No licence, or one that is not on LicenceUrls. */
    case Licence = 'licence';

    /** A photo that is not the rider's own names no author we could credit. */
    case NoAuthor = 'no_author';

    /** A scenic view, and where the camera stood is not known. */
    case CameraUnknown = 'camera_unknown';

    /** A scenic view, and the camera stood farther than PhotoValidator::MAX_CAMERA_DISTANCE_M from the pin. */
    case CameraFar = 'camera_far';

    /**
     * A scenic view, and a rider photo that counted as here until the pin
     * moved: its distance plus the move is over
     * PhotoValidator::MAX_CAMERA_DISTANCE_M, or a curator's "Taken here" was
     * made at the pin as it stood before.
     */
    case PinMoved = 'pin_moved';

    /**
     * True when the reason is about the place rather than the photo: the same
     * file may still be shown on another place.
     */
    public function concernsPlace(): bool
    {
        return self::CameraUnknown === $this || self::CameraFar === $this || self::PinMoved === $this;
    }
}
