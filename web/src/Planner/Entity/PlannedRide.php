<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Planner\Entity;

use App\Catalog\BikeType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One ride a rider planned and saved: its name, the bike it was routed for,
 * the stops in order, the routed line as the rider saw it when saving, and
 * the figures as saved.
 *
 * A ride is its rider's alone: no curator, public page, API or tile reads
 * it, and it goes with the account (`ON DELETE CASCADE` in the schema). The
 * line is not re-routed on opening; the first change re-routes the changed
 * legs in the browser.
 *
 * @see docs/specs/route-planner.md section 6.1
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'planned_ride')]
#[ORM\Index(name: 'idx_planned_ride_user', columns: ['user_id', 'updated_at'])]
class PlannedRide
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 16, enumType: BikeType::class)]
    private BikeType $bike = BikeType::Road;

    /**
     * The stops in order, each `{lat, lng, item?, name?}`.
     *
     * @var list<array{lat: float, lng: float, item?: string, name?: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $stops = [];

    /** GeoJSON (SRID 4326): LineString, the routed line as saved. */
    #[ORM\Column(type: 'geometry')]
    private string $line = '';

    #[ORM\Column(name: 'distance_m', type: Types::INTEGER)]
    private int $distanceM = 0;

    #[ORM\Column(name: 'ascent_m', type: Types::INTEGER)]
    private int $ascentM = 0;

    #[ORM\Column(name: 'descent_m', type: Types::INTEGER)]
    private int $descentM = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Everything a save carries, in one step, so an update is the same write
     * as an insert and moves `updatedAt` once.
     *
     * @param list<array{lat: float, lng: float, item?: string, name?: string}> $stops
     * @param string                                                            $lineGeoJson a GeoJSON LineString
     */
    public function fill(string $name, BikeType $bike, array $stops, string $lineGeoJson, int $distanceM, int $ascentM, int $descentM): static
    {
        $this->name = $name;
        $this->bike = $bike;
        $this->stops = $stops;
        $this->line = $lineGeoJson;
        $this->distanceM = $distanceM;
        $this->ascentM = $ascentM;
        $this->descentM = $descentM;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBike(): BikeType
    {
        return $this->bike;
    }

    /** @return list<array{lat: float, lng: float, item?: string, name?: string}> */
    public function getStops(): array
    {
        return $this->stops;
    }

    /** GeoJSON LineString. */
    public function getLine(): string
    {
        return $this->line;
    }

    public function getDistanceM(): int
    {
        return $this->distanceM;
    }

    public function getAscentM(): int
    {
        return $this->ascentM;
    }

    public function getDescentM(): int
    {
        return $this->descentM;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
