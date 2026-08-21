<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\BikeType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A rider's "I rode this". Distinct confirmers other than the proposer verify once they reach the threshold.
 *
 * @see docs/specs/route-domain.md §2.2, §6.2
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'route_ride')]
#[ORM\UniqueConstraint(name: 'uniq_route_ride', columns: ['route_id', 'user_id'])]
class RouteRide
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'route_id', type: Types::BIGINT)]
    private int $routeId;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(name: 'bike_type', type: Types::STRING, length: 12, enumType: BikeType::class)]
    private BikeType $bikeType;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $routeId, int $userId, BikeType $bikeType)
    {
        $this->routeId = $routeId;
        $this->userId = $userId;
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

    public function getBikeType(): BikeType
    {
        return $this->bikeType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
