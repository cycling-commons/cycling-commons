<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One desk item one curator has opened. Open work with no such row for its
 * reader carries the unseen bar; settled work never does.
 *
 * Written when the curator opens the item (its detail page or edit form
 * loads, the map drawer opens it, or they decide it), never because a list
 * showing it loaded. Reads and writes go through {@see \App\Moderation\DeskSeen}.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_seen')]
class ModerationSeen
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    /** A {@see \App\Moderation\SeenSubject} value. */
    #[ORM\Id]
    #[ORM\Column(name: 'subject_type', type: Types::STRING, length: 32)]
    private string $subjectType;

    #[ORM\Id]
    #[ORM\Column(name: 'subject_id', type: Types::STRING, length: 64)]
    private string $subjectId;

    #[ORM\Column(name: 'seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $seenAt;

    public function __construct(int $userId, string $subjectType, string $subjectId)
    {
        $this->userId = $userId;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->seenAt = new \DateTimeImmutable();
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getSeenAt(): \DateTimeImmutable
    {
        return $this->seenAt;
    }
}
