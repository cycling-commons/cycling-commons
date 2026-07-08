<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curated ride recommendation (letter K) — a composition, not an atomic map
 * feature.
 *
 * @api Catalog domain entity.
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

    /** region.id — routes span provinces; region is the operational scope. */
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
     * users.id of the proposing rider (route-domain spec §4.1); NULL for
     * imported routes. Plain column, no relation — provenance only, confers
     * no edit rights (spec D3).
     */
    #[ORM\Column(name: 'proposed_by', type: 'integer', nullable: true)]
    private ?int $proposedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** Last harvest touch — staleness signal (no auto-retire). */
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

        return $this;
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

    public function getDistanceM(): ?int
    {
        return $this->distanceM;
    }

    public function setDistanceM(?int $distanceM): static
    {
        $this->distanceM = $distanceM;

        return $this;
    }

    public function getAscentM(): ?int
    {
        return $this->ascentM;
    }

    public function setAscentM(?int $ascentM): static
    {
        $this->ascentM = $ascentM;

        return $this;
    }

    public function getRegionId(): ?int
    {
        return $this->regionId;
    }

    public function setRegionId(?int $regionId): static
    {
        $this->regionId = $regionId;

        return $this;
    }

    public function getState(): ItemState
    {
        return $this->state;
    }

    public function setState(ItemState $state): static
    {
        $this->state = $state;

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
        $this->updatedAt = new \DateTimeImmutable();

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
}
