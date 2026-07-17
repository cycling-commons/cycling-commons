<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\ItemSource;
use Doctrine\ORM\Mapping as ORM;

/**
 * Aggregate ride-heat point (layer L). Never editable, never moderated;
 * delete and reload on import.
 *
 * @see docs/specs/catalog-data-model.md §2.3
 *
 * @api Catalog domain entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'heat_point')]
class HeatPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** GeoJSON (SRID 4326): Point. */
    #[ORM\Column(type: 'geometry')]
    private ?string $geom = null;

    #[ORM\Column(type: 'float')]
    private float $weight = 1.0;

    #[ORM\Column(type: 'string', length: 10, enumType: ItemSource::class)]
    private ItemSource $source = ItemSource::Auto;

    /** Ride-heat layer filter facet ("summer"/"winter"/…); nullable, not every point carries one. */
    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $season = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $computedAt;

    public function __construct()
    {
        $this->computedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }

    public function setGeom(?string $geoJson): static
    {
        $this->geom = $geoJson;

        return $this;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function getSource(): ItemSource
    {
        return $this->source;
    }

    public function setSource(ItemSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSeason(): ?string
    {
        return $this->season;
    }

    public function setSeason(?string $season): static
    {
        $this->season = $season;

        return $this;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function setComputedAt(\DateTimeImmutable $computedAt): static
    {
        $this->computedAt = $computedAt;

        return $this;
    }
}
