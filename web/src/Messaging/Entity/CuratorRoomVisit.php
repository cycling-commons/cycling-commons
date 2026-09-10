<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a curator last had the room open. Feeds the Room tab's count.
 *
 * One row per curator, written on every room load whatever category is
 * showing: the room is one room, so seeing it is seeing it. A curator with no
 * row counts nothing, because the room's whole history is not unread.
 *
 * @see docs/specs/moderation-and-contribution.md §13.7
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_room_visit')]
class CuratorRoomVisit
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }
}
