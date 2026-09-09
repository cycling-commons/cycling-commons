<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Provider\Entity\DataProvider;
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

    /** Catalog letter (R lives on `recommended_route`; the derived heat layer has no letter and lives on `heat_point`). */
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

    /**
     * Which authority published this row, when one did.
     *
     * NULL for everything else: an OSM or Wikidata row is credited through
     * its own seeded registry row, and a rider's `manual`, `user` or `scout`
     * row has no publisher to credit at all. Set exactly when `source` is
     * {@see ItemSource::Authority}.
     *
     * `sourceRef` cannot answer this. It is the harvest's own upsert key and
     * the historical ones predate the registry: a Wallonia row still carries
     * `fx:pivot:hotel-koru|ramillies`, which names a bucket that no longer
     * exists rather than a provider.
     *
     * @see docs/specs/data-provider-hierarchy.md §3, §10
     */
    #[ORM\ManyToOne(targetEntity: DataProvider::class)]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?DataProvider $provider = null;

    /** Upstream id (node/…, way/…, Q…) or stable synthetic fx:* ref. Never NULL in practice. */
    #[ORM\Column(type: 'string', length: 160)]
    private string $sourceRef = '';

    /**
     * The OSM object this row is a record OF, whatever our own source is.
     *
     * OSM is the identity spine ([osm-data-architecture.md §1] — "the join key
     * between our data and OSM is always `osm_ref`"). `sourceRef` cannot serve
     * that role for every source: it is the harvest's own upsert key, so a
     * Wallonia row carries `fx:pivot:hotel-koru|ramillies` and could never match
     * `node/6123208864`. That mismatch is why one hotel was served twice, once
     * from the catalog and once from the coverage cache.
     *
     * NULL means "no OSM counterpart found", which is the honest majority: most
     * curated rows have none. It never means "not checked".
     *
     * @see docs/specs/catalog-data-model.md §5b
     */
    #[ORM\Column(type: 'string', length: 160, nullable: true)]
    private ?string $osmRef = null;

    /**
     * When the OSM question was last answered, whatever the answer was.
     *
     * NULL means nobody has looked. Set with `osmRef` NULL means somebody
     * looked and there is no OSM counterpart, which is a real answer and must
     * never be retried as though it were a gap.
     *
     * @see docs/specs/catalog-data-model.md §5b
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $osmCheckedAt = null;

    /**
     * The OSM-candidate list for an open question, computed once and stored
     * (App\Catalog\Import\OsmCandidates). NULL = compute on the next read;
     * the coverage harvest sets it back to NULL for every open row of the
     * harvested country. Written by SQL from the service, mapped here so the
     * schema tooling knows the columns.
     *
     * @var list<array{ref: string, name: ?string, distanceM: float}>|null
     *
     * @see docs/specs/catalog-data-model.md §5b
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $osmCandidates = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $osmCandidatesAt = null;

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

    /**
     * Last time an upstream export contained this row.
     *
     * Seeded at construction and rewritten by every harvest that sees the row,
     * including the pass where nothing changed. Read as "last seen upstream",
     * which is what puts a provider row on rung 4 of the evidence ladder
     * (docs/specs/data-provider-hierarchy.md §6.7.6). A row the publisher has
     * dropped simply stops advancing and falls off rung 4 on its own, so
     * upstream removal is derived and needs no second column. Never an
     * auto-retire (docs/specs/catalog-data-model.md §4).
     */
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

    public function getProvider(): ?DataProvider
    {
        return $this->provider;
    }

    public function setProvider(?DataProvider $provider): void
    {
        $this->provider = $provider;
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

    public function getOsmRef(): ?string
    {
        return $this->osmRef;
    }

    public function setOsmRef(?string $osmRef): static
    {
        $this->osmRef = ('' === $osmRef) ? null : $osmRef;

        return $this;
    }

    public function getOsmCheckedAt(): ?\DateTimeImmutable
    {
        return $this->osmCheckedAt;
    }

    /** @return list<array{ref: string, name: ?string, distanceM: float}>|null */
    public function getOsmCandidates(): ?array
    {
        return $this->osmCandidates;
    }

    public function getOsmCandidatesAt(): ?\DateTimeImmutable
    {
        return $this->osmCandidatesAt;
    }

    /** Has anyone said which OSM object this is, or that there is none? */
    public function osmAnswered(): bool
    {
        return null !== $this->osmCheckedAt;
    }

    /**
     * Answer the OSM question. A null (or empty) ref is the answer "this place
     * is not in OSM", not a clear.
     *
     * The only way both columns move together, so a row can never end up
     * carrying a ref nobody vouched for, or an answer with no timestamp
     * behind it (catalog-data-model.md §5b).
     */
    public function answerOsm(?string $ref): static
    {
        $this->setOsmRef($ref);
        $this->osmCheckedAt = new \DateTimeImmutable();

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
