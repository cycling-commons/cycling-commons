<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One room post one curator has opened. Feeds the Room tab's count.
 *
 * Written when the curator opens the post on the board, or follows the post's
 * own link to the submission it is about; never because a page listing it
 * loaded. A post with no row for a curator is unread to them, unless they
 * wrote it. Reads and writes go through {@see \App\Messaging\CuratorRoom}.
 *
 * @see docs/specs/moderation-and-contribution.md §13.7
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_post_read')]
#[ORM\Index(name: 'idx_curator_post_read_post', columns: ['post_id'])]
class CuratorPostRead
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'post_id', type: Types::BIGINT)]
    private int $postId;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $readAt;

    public function __construct(int $userId, int $postId)
    {
        $this->userId = $userId;
        $this->postId = $postId;
        $this->readAt = new \DateTimeImmutable();
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getPostId(): int
    {
        return $this->postId;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }
}
