<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use Doctrine\ORM\Mapping as ORM;

/**
 * A contribution awaiting (or past) moderation. The row also holds the decision audit.
 *
 * @see docs/specs/moderation-and-contribution.md §3.1
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'submission')]
#[ORM\Index(name: 'idx_submission_status', columns: ['status'])]
#[ORM\Index(name: 'idx_submission_country', columns: ['country_code'])]
#[ORM\Index(name: 'idx_submission_region', columns: ['region_id'])]
#[ORM\Index(name: 'idx_submission_item', columns: ['item_id'])]
#[ORM\Index(name: 'idx_submission_user', columns: ['user_id'])]
class Submission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 8, enumType: SubmissionType::class)]
    private SubmissionType $type = SubmissionType::NewItem;

    /** Catalog letter: practical A-G or experiential N-R. */
    #[ORM\Column(type: 'string', length: 1)]
    private string $letter = '';

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $itemId = null;

    /** users.id of the rider, 0 for the system, NULL once the rider's account is deleted. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $userId = 0;

    #[ORM\Column(type: 'string', length: 12, enumType: SubmissionStatus::class)]
    private SubmissionStatus $status = SubmissionStatus::Pending;

    #[ORM\Column(type: 'string', length: 200)]
    private string $title = '';

    /** GeoJSON Point (SRID 4326): the pending-pin location. */
    #[ORM\Column(type: 'geometry')]
    private ?string $geom = null;

    #[ORM\Column(type: 'string', length: 2)]
    private string $countryCode = '';

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $regionId = null;

    /**
     * Per-field snapshot: {field: {"was": mixed, "now": mixed}}.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $changes = [];

    /**
     * Raw validated form payload (durable record).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $decidedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * Legal hold: out of the queue, immune to Trash and retention. Only an admin can act.
     *
     * @see docs/specs/photo-uploads.md §6d
     */
    #[ORM\Column(name: 'escalated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $escalatedAt = null;

    #[ORM\Column(name: 'escalated_by_id', type: 'bigint', nullable: true)]
    private ?int $escalatedById = null;

    /** The curator's own words: the only description an admin has before deciding to look. */
    #[ORM\Column(name: 'escalated_reason', type: 'text', nullable: true)]
    private ?string $escalatedReason = null;

    /**
     * DSA Art. 18: when an administrator informed the competent authority.
     *
     * @see docs/specs/operations.md §7
     */
    #[ORM\Column(name: 'authority_notified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $authorityNotifiedAt = null;

    /** users.id of the administrator who recorded it; NULL once their account is deleted. */
    #[ORM\Column(name: 'authority_notified_by_id', type: 'bigint', nullable: true)]
    private ?int $authorityNotifiedById = null;

    #[ORM\Column(name: 'authority_name', type: 'string', length: 200, nullable: true)]
    private ?string $authorityName = null;

    #[ORM\Column(name: 'authority_reference', type: 'string', length: 200, nullable: true)]
    private ?string $authorityReference = null;

    /**
     * In the curators' Trash since then; deleted for good TrashBin::TRASH_DAYS later.
     *
     * @see docs/specs/moderation-and-contribution.md §6
     */
    #[ORM\Column(name: 'trashed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    #[ORM\Column(name: 'trashed_by', type: 'bigint', nullable: true)]
    private ?int $trashedBy = null;

    /** The status a restore puts back. */
    #[ORM\Column(name: 'trashed_from', type: 'string', length: 12, nullable: true, enumType: SubmissionStatus::class)]
    private ?SubmissionStatus $trashedFrom = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): SubmissionType
    {
        return $this->type;
    }

    public function setType(SubmissionType $type): static
    {
        $this->type = $type;

        return $this;
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

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function setItemId(?int $itemId): static
    {
        $this->itemId = $itemId;

        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getStatus(): SubmissionStatus
    {
        return $this->status;
    }

    public function setStatus(SubmissionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): static
    {
        $this->countryCode = strtoupper($countryCode);

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

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /** @param array<string, mixed> $changes */
    public function setChanges(array $changes): static
    {
        $this->changes = $changes;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function setDecisionNote(?string $decisionNote): static
    {
        $this->decisionNote = $decisionNote;

        return $this;
    }

    public function getDecidedBy(): ?int
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?int $decidedBy): static
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** A new hold is a new case: it starts without an authority notification. */
    public function escalate(int $curatorId, string $reason): void
    {
        $this->escalatedAt = new \DateTimeImmutable();
        $this->escalatedById = $curatorId;
        $this->escalatedReason = $reason;
        $this->authorityNotifiedAt = null;
        $this->authorityNotifiedById = null;
        $this->authorityName = null;
        $this->authorityReference = null;
    }

    /** DSA Art. 18 record. @see docs/specs/operations.md §7 */
    public function recordAuthorityNotice(\DateTimeImmutable $at, int $adminId, string $authority, ?string $reference): void
    {
        $this->authorityNotifiedAt = $at;
        $this->authorityNotifiedById = $adminId;
        $this->authorityName = $authority;
        $this->authorityReference = $reference;
    }

    public function getAuthorityNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->authorityNotifiedAt;
    }

    public function getAuthorityNotifiedById(): ?int
    {
        return $this->authorityNotifiedById;
    }

    public function getAuthorityName(): ?string
    {
        return $this->authorityName;
    }

    public function getAuthorityReference(): ?string
    {
        return $this->authorityReference;
    }

    /** An admin has decided it was not what it looked like; normal moderation resumes. */
    public function releaseEscalation(): void
    {
        $this->escalatedAt = null;
    }

    public function isEscalated(): bool
    {
        return null !== $this->escalatedAt;
    }

    public function getEscalatedAt(): ?\DateTimeImmutable
    {
        return $this->escalatedAt;
    }

    public function getEscalatedById(): ?int
    {
        return $this->escalatedById;
    }

    public function getEscalatedReason(): ?string
    {
        return $this->escalatedReason;
    }

    /** Into the bin: the status it had is kept for a restore. */
    public function moveToTrash(int $curatorId, \DateTimeImmutable $at): void
    {
        if (SubmissionStatus::Trashed === $this->status) {
            return;
        }
        $this->trashedFrom = $this->status;
        $this->status = SubmissionStatus::Trashed;
        $this->trashedAt = $at;
        $this->trashedBy = $curatorId;
    }

    /** Out of the bin, back to exactly the status it had. */
    public function restoreFromTrash(): void
    {
        if (SubmissionStatus::Trashed !== $this->status || null === $this->trashedFrom) {
            return;
        }
        $this->status = $this->trashedFrom;
        $this->trashedFrom = null;
        $this->trashedAt = null;
        $this->trashedBy = null;
    }

    public function isTrashed(): bool
    {
        return SubmissionStatus::Trashed === $this->status;
    }

    public function getTrashedAt(): ?\DateTimeImmutable
    {
        return $this->trashedAt;
    }

    public function getTrashedBy(): ?int
    {
        return $this->trashedBy;
    }

    public function getTrashedFrom(): ?SubmissionStatus
    {
        return $this->trashedFrom;
    }
}
