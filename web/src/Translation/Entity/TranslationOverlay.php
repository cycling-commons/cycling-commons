<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Live non-English override for one catalogue key. One row per (entry, locale).
 *
 * Surrogate id + unique (entry_id, locale) so a duplicate persist hits the DB
 * unique index (Doctrine identity-map collision on a composite PK would not).
 *
 * @see docs/specs/translations.md §3.1, §3.2
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'translation_overlay')]
#[ORM\UniqueConstraint(name: 'uniq_translation_overlay_entry_locale', columns: ['entry_id', 'locale'])]
class TranslationOverlay
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

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_proposal_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TranslationProposal $sourceProposal;

    #[ORM\Column(name: 'approved_by_id', type: Types::BIGINT, nullable: true)]
    private ?int $approvedById;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $approvedAt;

    public function __construct(
        TranslationEntry $entry,
        string $locale,
        string $value,
        ?TranslationProposal $sourceProposal,
        ?int $approvedById,
    ) {
        $this->entry = $entry;
        $this->locale = $locale;
        $this->value = $value;
        $this->sourceProposal = $sourceProposal;
        $this->approvedById = $approvedById;
        $this->approvedAt = new \DateTimeImmutable();
    }

    public function applyApproval(
        string $value,
        ?TranslationProposal $sourceProposal,
        ?int $approvedById,
        \DateTimeImmutable $approvedAt = new \DateTimeImmutable(),
    ): void {
        $this->value = $value;
        $this->sourceProposal = $sourceProposal;
        $this->approvedById = $approvedById;
        $this->approvedAt = $approvedAt;
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

    public function getValue(): string
    {
        return $this->value;
    }

    public function getSourceProposal(): ?TranslationProposal
    {
        return $this->sourceProposal;
    }

    public function getApprovedById(): ?int
    {
        return $this->approvedById;
    }

    public function getApprovedAt(): \DateTimeImmutable
    {
        return $this->approvedAt;
    }
}
