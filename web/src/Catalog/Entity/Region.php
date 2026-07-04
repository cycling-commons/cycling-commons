<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A named operational region (day-one seed: Wallonia). The future anchor for
 * per-region moderator groups, a user's preferred region and region-scoped
 * voting; today it drives item membership (item.region_id) at import.
 *
 * @api Catalog domain entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'region')]
class Region
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Stable machine reference, e.g. "wallonia" — the import upsert key. */
    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private string $slug = '';

    #[ORM\Column(type: 'string', length: 160)]
    private string $name = '';

    /** GeoJSON MultiPolygon (SRID 4326) via the "geometry" DBAL type. */
    #[ORM\Column(type: 'geometry', nullable: true)]
    private ?string $geom = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $areaKm2 = null;

    /** ISO 3166-1 alpha-2 of the country this region belongs to ('' = unset). */
    #[ORM\Column(type: 'string', length: 2, options: ['default' => ''])]
    private string $countryCode = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }

    public function setGeom(?string $geoJson): static
    {
        $this->geom = $geoJson;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAreaKm2(): ?float
    {
        return $this->areaKm2;
    }

    public function setAreaKm2(?float $areaKm2): static
    {
        $this->areaKm2 = $areaKm2;

        return $this;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): static
    {
        $this->countryCode = strtoupper($countryCode);

        return $this;
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
