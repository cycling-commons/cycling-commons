<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Provider\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dataset we take records from, and everything we owe it.
 *
 * The `authority` bucket used to be one enum case named after its first
 * member (`pivot`, Geoportail Wallonie), with the rider-facing citation a
 * string in a front-end constant. One provider fits in a constant; a world of
 * them does not. This table is the registry that replaces both.
 *
 * A dataset earns the `authority` rank when its publisher is the body of
 * record for the thing being mapped: RIVM publishes the Dutch tap register
 * because the water companies that fit the taps report to it. A dataset that
 * is merely somebody else's good map does not, and is either an OSM-grade
 * crowd source or not ingested at all.
 *
 * **OpenStreetMap and Wikidata are rows here too**, seeded with `system` set
 * so nobody can delete them. Their `endpoint` and `fieldMap` are ignored,
 * because their harvests are their own code; they live here so that ONE table
 * answers "who do we cite, and under what licence" for every row on the map.
 * A credits page assembled from two places drifts.
 *
 * `App\Provider` is its own top-level module beside `App\Catalog` and
 * `App\Coverage` because it is owned by neither: the catalog consumes its
 * rows, the coverage cache is suppressed by them, and the credits page and
 * the map both cite it (data-provider-hierarchy.md §9.1).
 *
 * @see docs/specs/data-provider-hierarchy.md §3, §9.1
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'data_provider')]
#[ORM\UniqueConstraint(name: 'uniq_data_provider_key', columns: ['provider_key'])]
class DataProvider
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /**
     * The stable slug, used in `source_ref` and in desk URLs.
     *
     * Immutable once rows reference it: an upsert key that changes is an
     * upsert key that stops matching, which turns every refresh into a full
     * set of duplicates.
     *
     * Stored as `provider_key` because `key` is reserved in several of the
     * places this column is read from.
     */
    #[ORM\Column(name: 'provider_key', type: Types::STRING, length: 64)]
    private string $key;

    /** What a rider sees in a drawer line: "RIVM", "Tourisme Wallonie". */
    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name;

    /** The long form, for the credits page. */
    #[ORM\Column(name: 'full_name', type: Types::STRING, length: 255)]
    private string $fullName;

    /** Where the citation links. */
    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $homepage;

    /** Free text for a reader: "Public Domain Mark 1.0", "CC BY 4.0". */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $licence;

    /**
     * The machine-readable form of the same fact.
     *
     * Separate from `licence` because it is what decides the credit's weight
     * on the credits page: a public-domain dataset owes no attribution line
     * and a CC BY one does.
     */
    #[ORM\Column(name: 'licence_code', type: Types::STRING, length: 40)]
    private string $licenceCode;

    /**
     * Who made the dataset, when that is not who publishes it.
     *
     * NULL when publisher and creator are the same body, which is the common
     * case. Named separately because a courtesy credit to the maker of a
     * dataset somebody else republishes is a debt the licence does not
     * always spell out.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $creator = null;

    /**
     * The credits sentence as a message key, for rows that live in git.
     *
     * What WE use the dataset for. That sentence is copy about us, not about
     * them, and it is the only translatable part of a credit row: a licence
     * name is not prose, and an attribution line must never be translated at
     * all. The seeded rows carry the keys the template already rendered, so
     * the registry took over the page without changing a word in any
     * language.
     *
     * @see docs/specs/data-provider-hierarchy.md §9.2
     */
    #[ORM\Column(name: 'blurb_key', type: Types::STRING, length: 120, nullable: true)]
    private ?string $blurbKey = null;

    /**
     * The same sentence as free English, for a row a curator adds.
     *
     * A curator cannot write Spanish, so this is English and the site
     * translates it the way it translates everything else. `blurbKey` wins
     * when both are set.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $blurb = null;

    /** Lifts a courtesy credit into a full row on the credits page. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $promoted = false;

    /** The exact line the licence obliges us to show, when it names one. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $attribution = null;

    /** ISO 3166-1 alpha-2, or NULL for a worldwide dataset. */
    #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2, nullable: true)]
    private ?string $countryCode = null;

    /**
     * Which catalogue letters this dataset fills, e.g. `["B"]` for taps.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $letters = [];

    /**
     * Where this dataset sits in the keeper hierarchy.
     *
     * Rider rows (`manual`, `user`, `scout`) sit above every row here and are
     * not expressible as one: a curator cannot give a provider a rank that
     * outranks a rider's own contribution. `auto` stays below everything.
     * Seeded to today's values, so admitting the registry moves nothing:
     * `osm` 100, `wikidata` 200, and the Wallonia rows above both.
     *
     * **0 means the question does not apply.** Rank orders authorities when
     * two of them describe one place; a boundary set, an extract mirror or a
     * photo library never produces an `item` row, and those are in the
     * registry to be cited rather than ranked.
     *
     * @see docs/specs/data-provider-hierarchy.md §4
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $rank = 300;

    /** False while nobody here has touched a row from this dataset. */
    #[ORM\Column(name: 'community_edited', type: Types::BOOLEAN)]
    private bool $communityEdited = false;

    /** URL of the machine-readable source. Ignored for a `system` row. */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $endpoint = null;

    /** `wfs`, `geojson`, `csv`, `arcgis`. Ignored for a `system` row. */
    #[ORM\Column(name: 'endpoint_kind', type: Types::STRING, length: 20, nullable: true)]
    private ?string $endpointKind = null;

    /**
     * Which upstream field feeds which of our attributes.
     *
     * @var array<string, string|array{from: string, values: array<string, string>}>
     */
    #[ORM\Column(name: 'field_map', type: Types::JSON)]
    private array $fieldMap = [];

    /**
     * How close an upstream point must be to an OSM node to be the same thing.
     *
     * A judgement per provider, not a constant: for the Dutch taps 25 m
     * under-matches and creates duplicate pins, 250 m can swallow two real
     * taps at either end of a square.
     *
     * @see docs/specs/data-provider-hierarchy.md §5.1
     */
    #[ORM\Column(name: 'match_radius_m', type: Types::INTEGER)]
    private int $matchRadiusM = 50;

    /**
     * Which OSM tags count as the same THING as this dataset's records.
     *
     * A letter is not a kind. Letter B holds 7024 rows in the Netherlands, of
     * which 2744 are `amenity=drinking_water` and the rest are toilets, water
     * points, cafés and 3910 rows with no `amenity` at all; matching a public
     * tap by letter alone tied it to the café across the road. This narrows
     * the match to tags that mean the same thing: `{"amenity":
     * ["drinking_water", "water_point"]}`.
     *
     * NULL keeps the letter-wide match, which is right for a dataset whose
     * letter really is its kind.
     *
     * @var array<string, list<string>>|null
     *
     * @see docs/specs/data-provider-hierarchy.md §5
     */
    #[ORM\Column(name: 'match_tags', type: Types::JSON, nullable: true)]
    private ?array $matchTags = null;

    /** Free text for a curator: "twice yearly", "monthly". */
    #[ORM\Column(name: 'refresh_cadence', type: Types::STRING, length: 60, nullable: true)]
    private ?string $refreshCadence = null;

    #[ORM\Column(name: 'last_run_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'last_count', type: Types::INTEGER, nullable: true)]
    private ?int $lastCount = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /**
     * Whether this provider may take a record back from the riders at harvest
     * (docs/specs/data-provider-hierarchy.md §6.7.2). A judgement about the
     * organisation's field operation, so it lives beside the provider and not
     * in the rule. Off by default: a provider earns it by showing dated
     * surveys.
     */
    #[ORM\Column(name: 'may_reclaim', type: Types::BOOLEAN)]
    private bool $mayReclaim = false;

    /**
     * How many days newer than our newest confirmation the provider's survey
     * must be before custody moves. One day would bounce a pin on every
     * harvest.
     */
    #[ORM\Column(name: 'reclaim_margin_days', type: Types::INTEGER)]
    private int $reclaimMarginDays = 30;

    /**
     * The harvested attribute that carries the provider's per-record survey
     * date (`YYYY-MM-DD`, longer values truncated to the day). NULL means the
     * provider publishes no dates, and a provider with no dates can never
     * reclaim; it is also the published witness rung 8 reads.
     */
    #[ORM\Column(name: 'survey_date_attribute', type: Types::STRING, length: 64, nullable: true)]
    private ?string $surveyDateAttribute = null;

    /** Off means "keep the rows, stop refreshing", never "delete the rows". */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    /** True for the seeded rows nobody may delete. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $system = false;

    public function __construct(
        string $key,
        string $name,
        string $fullName,
        string $homepage,
        string $licence,
        string $licenceCode,
        int $rank,
    ) {
        $this->key = $key;
        $this->name = $name;
        $this->fullName = $fullName;
        $this->homepage = $homepage;
        $this->licence = $licence;
        $this->licenceCode = $licenceCode;
        $this->rank = $rank;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): void
    {
        $this->fullName = $fullName;
    }

    public function getHomepage(): string
    {
        return $this->homepage;
    }

    public function setHomepage(string $homepage): void
    {
        $this->homepage = $homepage;
    }

    public function getLicence(): string
    {
        return $this->licence;
    }

    public function setLicence(string $licence): void
    {
        $this->licence = $licence;
    }

    public function getLicenceCode(): string
    {
        return $this->licenceCode;
    }

    public function setLicenceCode(string $licenceCode): void
    {
        $this->licenceCode = $licenceCode;
    }

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(?string $creator): void
    {
        $this->creator = $creator;
    }

    public function isPromoted(): bool
    {
        return $this->promoted;
    }

    public function setPromoted(bool $promoted): void
    {
        $this->promoted = $promoted;
    }

    public function getBlurbKey(): ?string
    {
        return $this->blurbKey;
    }

    public function setBlurbKey(?string $blurbKey): void
    {
        $this->blurbKey = $blurbKey;
    }

    public function getBlurb(): ?string
    {
        return $this->blurb;
    }

    public function setBlurb(?string $blurb): void
    {
        $this->blurb = $blurb;
    }

    public function getAttribution(): ?string
    {
        return $this->attribution;
    }

    public function setAttribution(?string $attribution): void
    {
        $this->attribution = $attribution;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): void
    {
        $this->countryCode = $countryCode;
    }

    /** @return list<string> */
    public function getLetters(): array
    {
        return $this->letters;
    }

    /** @param list<string> $letters */
    public function setLetters(array $letters): void
    {
        $this->letters = $letters;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function setRank(int $rank): void
    {
        $this->rank = $rank;
    }

    public function isCommunityEdited(): bool
    {
        return $this->communityEdited;
    }

    public function setCommunityEdited(bool $communityEdited): void
    {
        $this->communityEdited = $communityEdited;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): void
    {
        $this->endpoint = $endpoint;
    }

    public function getEndpointKind(): ?string
    {
        return $this->endpointKind;
    }

    public function setEndpointKind(?string $endpointKind): void
    {
        $this->endpointKind = $endpointKind;
    }

    /** @return array<string, string|array{from: string, values: array<string, string>}> */
    public function getFieldMap(): array
    {
        return $this->fieldMap;
    }

    /**
     * Ours to theirs: a plain upstream field name, or `{from, values}` when
     * the upstream values must be translated into one of our vocabularies
     * (pipeline/providers/normalise.py apply_field_map).
     *
     * @param array<string, string|array{from: string, values: array<string, string>}> $fieldMap
     */
    public function setFieldMap(array $fieldMap): void
    {
        $this->fieldMap = $fieldMap;
    }

    public function getMatchRadiusM(): int
    {
        return $this->matchRadiusM;
    }

    public function setMatchRadiusM(int $matchRadiusM): void
    {
        $this->matchRadiusM = $matchRadiusM;
    }

    /** @return array<string, list<string>>|null */
    public function getMatchTags(): ?array
    {
        return $this->matchTags;
    }

    /** @param array<string, list<string>>|null $matchTags */
    public function setMatchTags(?array $matchTags): void
    {
        $this->matchTags = $matchTags;
    }

    public function getRefreshCadence(): ?string
    {
        return $this->refreshCadence;
    }

    public function setRefreshCadence(?string $refreshCadence): void
    {
        $this->refreshCadence = $refreshCadence;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastCount(): ?int
    {
        return $this->lastCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** Records the outcome of a harvest, whichever way it went. */
    public function recordRun(\DateTimeImmutable $at, ?int $count, ?string $error): void
    {
        $this->lastRunAt = $at;
        $this->lastCount = $count;
        $this->lastError = $error;
    }

    public function mayReclaim(): bool
    {
        return $this->mayReclaim;
    }

    public function setMayReclaim(bool $mayReclaim): void
    {
        $this->mayReclaim = $mayReclaim;
    }

    public function getReclaimMarginDays(): int
    {
        return $this->reclaimMarginDays;
    }

    public function setReclaimMarginDays(int $days): void
    {
        $this->reclaimMarginDays = $days;
    }

    public function getSurveyDateAttribute(): ?string
    {
        return $this->surveyDateAttribute;
    }

    public function setSurveyDateAttribute(?string $attribute): void
    {
        $this->surveyDateAttribute = $attribute;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * Marks a row as one of the seeded ones nobody may delete.
     *
     * No setter to turn it back off: a row becomes untouchable by being one
     * of ours, and a curator who could clear the flag could then delete the
     * OpenStreetMap row that every raw pin on the map is credited to.
     */
    public function markSystem(): void
    {
        $this->system = true;
    }
}
