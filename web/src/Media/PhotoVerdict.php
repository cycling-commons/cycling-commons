<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * PhotoValidator's answer: a decision, and the reason when it is not `show`.
 *
 * @see docs/specs/photo-uploads.md §5h
 *
 * @api
 */
final readonly class PhotoVerdict
{
    public function __construct(
        public PhotoDecision $decision,
        public ?PhotoReason $reason = null,
    ) {
    }

    public static function show(): self
    {
        return new self(PhotoDecision::Show);
    }

    /** Linked and shown. */
    public function shows(): bool
    {
        return PhotoDecision::Show === $this->decision;
    }

    /** Linked, whether shown or hidden. */
    public function links(): bool
    {
        return PhotoDecision::Refuse !== $this->decision;
    }
}
