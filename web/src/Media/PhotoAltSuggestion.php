<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\Uid\Uuid;

/**
 * "This photograph should say something else" as a change on a submission.
 *
 * A description belongs to whoever uploaded the picture, and
 * `MediaController::altText()` will only take one from them. Everybody else
 * used to have nowhere to go: a photo with a wrong or missing description
 * stayed that way unless its uploader came back (owner, 2026-08-30).
 *
 * So a non-owner SUGGESTS one, through the queue every other field on that
 * wizard already goes through. No new moderation mechanic: it is an edit on the
 * item, a curator sees it beside the rest, and approving it applies the words.
 *
 * The change key carries the photo's uuid, because a submission's changes are a
 * flat `field => {was, now}` map and one item can hold several pictures. It is
 * namespaced so it can never collide with a real attribute name: no catalogue
 * field contains a colon.
 *
 * @see docs/specs/photo-uploads.md §5e
 *
 * @api
 */
final class PhotoAltSuggestion
{
    /** Colons cannot appear in a catalogue field name, so nothing collides. */
    public const string PREFIX = 'photoAlt:';

    /** The `changes` key for one picture's description. */
    public static function field(Uuid $photo): string
    {
        return self::PREFIX.$photo->toRfc4122();
    }

    /** The picture a change key is about, or null when it is an ordinary field. */
    public static function photoOf(string $field): ?Uuid
    {
        if (!str_starts_with($field, self::PREFIX)) {
            return null;
        }

        $id = substr($field, \strlen(self::PREFIX));

        return Uuid::isValid($id) ? Uuid::fromString($id) : null;
    }
}
