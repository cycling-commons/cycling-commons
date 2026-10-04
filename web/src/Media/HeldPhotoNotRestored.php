<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * A released photo's variants could not go back to their public key, so the
 * legal hold stays in force.
 *
 * @see docs/specs/photo-uploads.md §6d
 */
final class HeldPhotoNotRestored extends \RuntimeException
{
    public function __construct(string $mediaId, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('Photo %s could not go back to its public key; the hold stays.', $mediaId), 0, $previous);
    }
}
