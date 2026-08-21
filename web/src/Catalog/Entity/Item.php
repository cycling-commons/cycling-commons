<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\Mapping as ORM;

/**
 * An atomic editable catalog feature. Filterable fields are columns; type-specific detail is jsonb `attributes`.
 *
 * @see docs/specs/catalog-data-model.md §2.1
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'item')]
#[ORM\Index(name: 'idx_item_letter_state', columns: ['letter', 'state'])]
#[ORM\Index(name: 'idx_item_country', columns: ['country_code'])]
#[ORM\Index(name: 'idx_item_region', columns: ['region_id'])]
#[ORM\UniqueConstraint(name: 'uniq_item_source_ref_letter', columns: ['source', 'source_ref', 'letter'])]
class Item
{
    /** Lives on Item::name, never in jsonb `attributes`. */
    public const string NAME_FIELD = 'name';

    /** Moved pin travels as this pseudo-field; the position lives in `geom`, not attributes. */
    public const string LOCATION_FIELD = 'location';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Catalog letter (K and L live on other tables). */
    #[ORM\Column(type: 'string', length: 1)]
    private string $letter = '';

    #[ORM\Column(type: 'string', length: 200)]
    private string $name = '';

    /** GeoJSON (SRID 4326): Point, or LineString for letter A. */
    #[ORM\Column(type: 'geometry')]
    private ?string $geom = null;

    #[ORM\Column(type: 'string', length: 2)]
    private string $countryCode = '';

    /** Plain column (no FK object): world_subdivision.id, resolved at import. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $subdivisionId = null;

    /** region.id: membership recomputed on every import run. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $regionId = null;

    #[ORM\Column(type: 'string', length: 12, enumType: ItemState::class)]
    private ItemState $state = ItemState::Unverified;

    #[ORM\Column(type: 'string', length: 10, enumType: ItemSource::class)]
    private ItemSource $source = ItemSource::Auto;

    /** Upstream id (node/…, way/…, Q…) or stable synthetic fx:* ref. Never NULL in practice. */
    #[ORM\Column(type: 'string', length: 160)]
    private string $sourceRef = '';

    /**
     * Registry-validated type-specific + display fields.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $attributes = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** Last harvest touch: staleness signal, no auto-retire. docs/specs/catalog-data-model.md §4 */
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

    public function getLetter(): string
    {
        return $this->letter;
    }

    public function setLetter(string $letter): static
    {
        $this->letter = strtoupper($letter);

        return $this;
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

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): static
    {
        $this->countryCode = strtoupper($countryCode);

        return $this;
    }

    public function getSubdivisionId(): ?int
    {
        return $this->subdivisionId;
    }

    public function setSubdivisionId(?int $subdivisionId): static
    {
        $this->subdivisionId = $subdivisionId;

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
