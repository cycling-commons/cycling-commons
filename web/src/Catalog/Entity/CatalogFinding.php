<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\FindingKind;
use App\Catalog\FindingStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * A machine-raised finding about catalog data, for the curator data desk.
 *
 * **Not a submission, and deliberately not in that table.** A submission is a
 * person's contribution: it carries a rider identity, it earns a reply when it
 * is decided, and it rides the retention clock. None of that is true here.
 * Nobody proposed these; a scan did. Filing them as submissions would mean a
 * moderator owes an answer to a machine, and would put machine output in the
 * same queue as rider work — the two need different attention, not the same
 * list.
 *
 * What makes it one desk rather than a desk per check is {@see FindingKind}:
 * the duplicate scan and the OSM-link matcher are the first two cases, and the
 * checks `prescreen_seeded.py` already computes drop in behind them without new
 * mechanics.
 *
 * Scope is read through the item, never copied here: `item.region_id` and
 * `item.country_code` are recomputed from geometry on every import, and a copy
 * on this row would be a second truth that goes stale the first time a boundary
 * moves.
 *
 * @see docs/specs/catalog-data-model.md §5c
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_finding')]
#[ORM\UniqueConstraint(name: 'uniq_finding_identity', columns: ['kind', 'item_id', 'related_item_id', 'osm_ref'])]
class CatalogFinding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32, enumType: FindingKind::class)]
    private FindingKind $kind;

    /** The row the finding is about. */
    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    /** The other row, for a finding about a pair. NULL for the rest. */
    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(name: 'related_item_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Item $relatedItem = null;

    /** The OSM object being proposed, for `osm_link`. NULL for the rest. */
    #[ORM\Column(type: 'string', length: 160, nullable: true)]
    private ?string $osmRef = null;

    /**
     * Whatever this kind needs to be judged without re-running the scan:
     * the distance, the two names, the matched key.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $detail = [];

    #[ORM\Column(type: 'string', length: 12, enumType: FindingStatus::class)]
    private FindingStatus $status = FindingStatus::Open;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $decidedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Why a curator dismissed it. Kept because the reason is the useful part. */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $detail */
    public function __construct(FindingKind $kind, Item $item, array $detail = [])
    {
        $this->kind = $kind;
        $this->item = $item;
        $this->detail = $detail;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): FindingKind
    {
        return $this->kind;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function getRelatedItem(): ?Item
    {
        return $this->relatedItem;
    }

    public function setRelatedItem(?Item $item): static
    {
        $this->relatedItem = $item;
        $this->touch();

        return $this;
    }

    public function getOsmRef(): ?string
    {
        return $this->osmRef;
    }

    public function setOsmRef(?string $osmRef): static
    {
        $this->osmRef = ('' === $osmRef) ? null : $osmRef;
        $this->touch();

        return $this;
    }

    /** @return array<string, mixed> */
    public function getDetail(): array
    {
        return $this->detail;
    }

    /** @param array<string, mixed> $detail */
    public function setDetail(array $detail): static
    {
        $this->detail = $detail;
        $this->touch();

        return $this;
    }

    public function getStatus(): FindingStatus
    {
        return $this->status;
    }

    public function getDecidedBy(): ?int
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Record a curator's decision.
     *
     * The decider is stored for every outcome, including a dismissal: "these
     * are two different bunkers" is a judgement someone made, and the next
     * curator to wonder why the desk is quiet about Ligne KW needs to see who
     * decided that and when.
     */
    public function decide(FindingStatus $status, int $userId, ?string $note = null): static
    {
        $this->status = $status;
        $this->decidedBy = $userId;
        $this->decidedAt = new \DateTimeImmutable();
        $this->note = ('' === $note) ? null : $note;
        $this->touch();

        return $this;
    }

    /** Closed by the data changing, not by a person — so no decider is recorded. */
    public function resolveAutomatically(): static
    {
        $this->status = FindingStatus::Resolved;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
