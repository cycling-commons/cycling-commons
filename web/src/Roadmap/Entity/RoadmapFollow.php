<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One rider following one roadmap item: a message in their inbox when it
 * moves to another group, ships, or leaves the roadmap. One per rider per
 * item. Written by RoadmapMarks with DBAL; mapped for reading and for schema
 * diffs.
 *
 * `item_ref` names what was marked: `item:7` for a roadmap item, or
 * `proposal:42` for a rider's proposal open for votes. A promoted
 * proposal's rows move to its item; the rows go when the item leaves the
 * roadmap and when the account goes.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'roadmap_follow')]
#[ORM\UniqueConstraint(name: 'uniq_roadmap_follow', columns: ['user_id', 'item_ref'])]
#[ORM\Index(name: 'idx_roadmap_follow_item', columns: ['item_ref'])]
class RoadmapFollow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(name: 'item_ref', type: Types::STRING, length: 80)]
    private string $itemRef;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $userId, string $itemRef)
    {
        $this->userId = $userId;
        $this->itemRef = $itemRef;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getItemRef(): string
    {
        return $this->itemRef;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
