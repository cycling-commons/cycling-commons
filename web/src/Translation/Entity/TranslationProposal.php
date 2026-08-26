<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Entity;

use App\Translation\TranslationProposalStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Rider-proposed non-English value awaiting (or past) curator decision.
 *
 * @see docs/specs/translations.md §3.1
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'translation_proposal')]
#[ORM\Index(name: 'idx_translation_proposal_status_locale', columns: ['status', 'locale'])]
class TranslationProposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', nullable: false)]
    private TranslationEntry $entry;

    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $locale;

    #[ORM\Column(name: 'proposed_value', type: Types::TEXT)]
    private string $proposedValue;

    #[ORM\Column(name: 'published_value', type: Types::TEXT, nullable: true)]
    private ?string $publishedValue = null;

    #[ORM\Column(name: 'english_at_submit', type: Types::TEXT)]
    private string $englishAtSubmit;

    #[ORM\Column(name: 'submitter_id', type: Types::BIGINT, nullable: true)]
    private ?int $submitterId;

    #[ORM\Column(name: 'consent_record_id', type: 'uuid')]
    private Uuid $consentRecordId;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: TranslationProposalStatus::class)]
    private TranslationProposalStatus $status;

    #[ORM\Column(name: 'reviewer_id', type: Types::BIGINT, nullable: true)]
    private ?int $reviewerId = null;

    #[ORM\Column(name: 'reviewer_note', type: Types::TEXT, nullable: true)]
    private ?string $reviewerNote = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(
        TranslationEntry $entry,
        string $locale,
        string $proposedValue,
        string $englishAtSubmit,
        ?int $submitterId,
        Uuid $consentRecordId,
    ) {
        $this->entry = $entry;
        $this->locale = $locale;
        $this->proposedValue = $proposedValue;
        $this->englishAtSubmit = $englishAtSubmit;
        $this->submitterId = $submitterId;
        $this->consentRecordId = $consentRecordId;
        $this->status = TranslationProposalStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setStatus(TranslationProposalStatus $status): void
    {
        $this->status = $status;
    }

    public function setProposedValue(string $proposedValue): void
    {
        $this->proposedValue = $proposedValue;
    }

    public function setPublishedValue(?string $publishedValue): void
    {
        $this->publishedValue = $publishedValue;
    }

    public function setEnglishAtSubmit(string $englishAtSubmit): void
    {
        $this->englishAtSubmit = $englishAtSubmit;
    }

    public function setConsentRecordId(Uuid $consentRecordId): void
    {
        $this->consentRecordId = $consentRecordId;
    }

    public function setReviewerId(?int $reviewerId): void
    {
        $this->reviewerId = $reviewerId;
    }

    public function setReviewerNote(?string $reviewerNote): void
    {
        $this->reviewerNote = $reviewerNote;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): void
    {
        $this->decidedAt = $decidedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntry(): TranslationEntry
    {
        return $this->entry;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getProposedValue(): string
    {
        return $this->proposedValue;
    }

    /** Wording that went live; falls back to the rider's proposal if never copy-edited. */
    public function getPublishedValue(): string
    {
        return $this->publishedValue ?? $this->proposedValue;
    }

    public function getEnglishAtSubmit(): string
    {
        return $this->englishAtSubmit;
    }

    public function getSubmitterId(): ?int
    {
        return $this->submitterId;
    }

    public function getConsentRecordId(): Uuid
    {
        return $this->consentRecordId;
    }

    public function getStatus(): TranslationProposalStatus
    {
        return $this->status;
    }

    public function getReviewerId(): ?int
    {
        return $this->reviewerId;
    }

    public function getReviewerNote(): ?string
    {
        return $this->reviewerNote;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
