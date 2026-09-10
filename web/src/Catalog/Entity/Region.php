<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Named operational region. Never delete a region row.
 *
 * @see docs/specs/catalog-data-model.md §2.4
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'region')]
class Region
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Stable machine reference, e.g. "wallonia": the import upsert key. */
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

    /** ISO 3166-2 subdivision code (e.g. BE-WAL), joins World Subdivision.code. */
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $isoCode = null;

    /** Administrative level of the source boundary (Belgium: 4). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $adminLevel = null;

    /** Polygon provenance: `osm` | `overture`. docs/specs/map-and-search.md §4.5 */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $source = null;

    /**
     * Per-region override of `route.region_active_cap`; NULL = global default. Set via admin, never by import.
     *
     * @see docs/specs/route-domain.md §5.1
     */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $activeCap = null;

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

    public function getIsoCode(): ?string
    {
        return $this->isoCode;
    }

    public function setIsoCode(?string $isoCode): static
    {
        $this->isoCode = $isoCode;

        return $this;
    }

    public function getAdminLevel(): ?int
    {
        return $this->adminLevel;
    }

    public function setAdminLevel(?int $adminLevel): static
    {
        $this->adminLevel = $adminLevel;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getActiveCap(): ?int
    {
        return $this->activeCap;
    }

    public function setActiveCap(?int $activeCap): static
    {
        $this->activeCap = $activeCap;

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
