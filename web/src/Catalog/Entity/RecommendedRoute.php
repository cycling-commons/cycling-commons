<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curated ride recommendation (letter R).
 *
 * @see docs/specs/catalog-data-model.md §2.2
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'recommended_route')]
#[ORM\Index(name: 'idx_route_region', columns: ['region_id'])]
#[ORM\UniqueConstraint(name: 'uniq_route_source_ref', columns: ['source', 'source_ref'])]
class RecommendedRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name = '';

    /** GeoJSON (SRID 4326): LineString. */
    #[ORM\Column(type: 'geometry')]
    private ?string $geom = null;

    #[ORM\Column(name: 'distance_m', type: 'integer', nullable: true)]
    private ?int $distanceM = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ascentM = null;

    /** region.id: routes span provinces, so region is the operational scope. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $regionId = null;

    #[ORM\Column(type: 'string', length: 12, enumType: ItemState::class)]
    private ItemState $state = ItemState::Unverified;

    #[ORM\Column(type: 'string', length: 10, enumType: ItemSource::class)]
    private ItemSource $source = ItemSource::Auto;

    /** Upstream id or stable synthetic fx:* ref. Never NULL in practice. */
    #[ORM\Column(type: 'string', length: 160)]
    private string $sourceRef = '';

    /**
     * Registry-validated type-specific + display fields.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $attributes = [];

    /**
     * Proposing rider; NULL for imported routes. Provenance only, no edit rights.
     *
     * @see docs/specs/route-domain.md §2.1
     */
    #[ORM\Column(name: 'proposed_by', type: 'integer', nullable: true)]
    private ?int $proposedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** Last harvest touch: a staleness signal (no auto-retire). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }

    public function setGeom(?string $geoJson): static
    {
        $this->geom = $geoJson;
        $this->touch();

        return $this;
    }

    public function getDistanceM(): ?int
    {
        return $this->distanceM;
    }

    public function setDistanceM(?int $distanceM): static
    {
        $this->distanceM = $distanceM;
        $this->touch();

        return $this;
    }

    public function getAscentM(): ?int
    {
        return $this->ascentM;
    }

    public function setAscentM(?int $ascentM): static
    {
        $this->ascentM = $ascentM;
        $this->touch();

        return $this;
    }

    public function getRegionId(): ?int
    {
        return $this->regionId;
    }

    public function setRegionId(?int $regionId): static
    {
        $this->regionId = $regionId;
        $this->touch();

        return $this;
    }

    public function getState(): ItemState
    {
        return $this->state;
    }

    /**
     * A state change is a change to what the catalog serves, so it moves
     * `updatedAt`, exactly as {@see Item::setState()} does. Catalog freshness
     * reads that column ({@see \App\Catalog\CatalogProvider::regionStamps()}),
     * and an approve, reject, retire or trash changes no other column: the row
     * already exists as `submitted`, so the row count does not move either. A
     * silent setter here is a rider watching their approved route stay
     * invisible for an hour (owner-reported 2026-09-16, route 111
     * "Liege Bastogne Liege", approved 19:52 and still absent on reload).
     */
    public function setState(ItemState $state): static
    {
        $this->state = $state;
        $this->touch();

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

    public function getSourceRef(): string
    {
        return $this->sourceRef;
    }

    public function setSourceRef(string $sourceRef): static
    {
        $this->sourceRef = $sourceRef;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;
        $this->touch();

        return $this;
    }

    public function getProposedBy(): ?int
    {
        return $this->proposedBy;
    }

    public function setProposedBy(?int $proposedBy): static
    {
        $this->proposedBy = $proposedBy;

        return $this;
    }

    public function getImportedAt(): ?\DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(?\DateTimeImmutable $importedAt): static
    {
        $this->importedAt = $importedAt;

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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
