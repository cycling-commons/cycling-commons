<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

/**
 * How far a curator-room post is pinned.
 *
 * @see docs/specs/moderation-and-contribution.md §13.5
 *
 * @api
 */
enum CuratorRoomPin: string
{
    /** Ordinary post, ordered by age. */
    case None = 'none';
    /** Top of its own category view only. */
    case Category = 'category';
    /** Top of every view, category views included. */
    case Room = 'room';

    public function isPinned(): bool
    {
        return self::None !== $this;
    }

    public function labelKey(): string
    {
        return 'room.pin.'.$this->value;
    }
}
