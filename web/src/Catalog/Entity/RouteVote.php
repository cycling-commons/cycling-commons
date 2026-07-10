<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\BikeType;
use App\Catalog\Season;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A typed seasonal vote (route-domain spec §4.2, §7): "I recommend this as a
 * [season] ride on [bike type]." One per user per route per season (UNIQUE);
 * `created_at` enables the future annual reset (spec non-goal). Votes are only
 * accepted on `verified` routes (spec D7) — enforced in the controller.
 *
 * @api Created by RouteCommunityService (phase 3); ranked in phase 4.
 */
#[ORM\Entity]
#[ORM\Table(name: 'route_vote')]
#[ORM\UniqueConstraint(name: 'uniq_route_vote', columns: ['route_id', 'user_id', 'season'])]
#[ORM\Index(name: 'idx_route_vote_rank', columns: ['route_id', 'season', 'bike_type'])]
class RouteVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'route_id', type: Types::BIGINT)]
    private int $routeId;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: Season::class)]
    private Season $season;

    #[ORM\Column(name: 'bike_type', type: Types::STRING, length: 12, enumType: BikeType::class)]
    private BikeType $bikeType;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $routeId, int $userId, Season $season, BikeType $bikeType)
    {
        $this->routeId = $routeId;
        $this->userId = $userId;
        $this->season = $season;
        $this->bikeType = $bikeType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRouteId(): int
    {
        return $this->routeId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function getBikeType(): BikeType
    {
        return $this->bikeType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
