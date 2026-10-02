<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\BikeType;
use App\Catalog\ItemType;
use App\Catalog\Season;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One vote on the season ballot. Written by BallotService with DBAL, so a
 * slot collision cannot close the entity manager; mapped for reading and for
 * schema diffs.
 *
 * @see docs/specs/route-domain.md §2.2, §8d
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'season_vote')]
#[ORM\UniqueConstraint(name: 'uniq_season_vote_subject', columns: ['user_id', 'region_id', 'category', 'round_start', 'subject_id'])]
#[ORM\UniqueConstraint(name: 'uniq_season_vote_slot', columns: ['user_id', 'region_id', 'category', 'round_start', 'slot'])]
#[ORM\Index(name: 'idx_season_vote_list', columns: ['region_id', 'category', 'round_start'])]
#[ORM\Index(name: 'idx_season_vote_subject', columns: ['category', 'subject_id'])]
class SeasonVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(name: 'region_id', type: Types::BIGINT)]
    private int $regionId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ItemType::class)]
    private ItemType $category;

    #[ORM\Column(name: 'subject_id', type: Types::BIGINT)]
    private int $subjectId;

    #[ORM\Column(name: 'bike_type', type: Types::STRING, length: 12, nullable: true, enumType: BikeType::class)]
    private ?BikeType $bikeType;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: Season::class)]
    private Season $season;

    #[ORM\Column(name: 'round_start', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $roundStart;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $slot;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $userId, int $regionId, ItemType $category, int $subjectId, ?BikeType $bikeType, Season $season, \DateTimeImmutable $roundStart, int $slot)
    {
        $this->userId = $userId;
        $this->regionId = $regionId;
        $this->category = $category;
        $this->subjectId = $subjectId;
        $this->bikeType = $bikeType;
        $this->season = $season;
        $this->roundStart = $roundStart;
        $this->slot = $slot;
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

    public function getRegionId(): int
    {
        return $this->regionId;
    }

    public function getCategory(): ItemType
    {
        return $this->category;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function getBikeType(): ?BikeType
    {
        return $this->bikeType;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function getRoundStart(): \DateTimeImmutable
    {
        return $this->roundStart;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
