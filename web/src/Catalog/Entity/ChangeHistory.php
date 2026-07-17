<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One applied field change on a catalog item. Append-only: no code path
 * may ever update or delete rows. old_value records the item's actual
 * value at apply time, never the submitter's possibly-stale snapshot.
 * Time-partitionable from day one: nothing references its id.
 *
 * @see docs/specs/moderation-and-contribution.md §4.1
 *
 * @api Catalog domain entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'change_history')]
#[ORM\Index(name: 'idx_history_item_time', columns: ['item_id', 'changed_at'])]
class ChangeHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $itemId = 0;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $submissionId = null;

    /** Attribute key, or the pseudo-fields "name" / "state". */
    #[ORM\Column(type: 'string', length: 80)]
    private string $field = '';

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private mixed $oldValue = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private mixed $newValue = null;

    #[ORM\Column(type: 'bigint')]
    private int $changedBy = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $changedAt;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function setItemId(int $itemId): static
    {
        $this->itemId = $itemId;

        return $this;
    }

    public function getSubmissionId(): ?int
    {
        return $this->submissionId;
    }

    public function setSubmissionId(?int $submissionId): static
    {
        $this->submissionId = $submissionId;

        return $this;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function setField(string $field): static
    {
        $this->field = $field;

        return $this;
    }

    public function getOldValue(): mixed
    {
        return $this->oldValue;
    }

    public function setOldValue(mixed $oldValue): static
    {
        $this->oldValue = $oldValue;

        return $this;
    }

    public function getNewValue(): mixed
    {
        return $this->newValue;
    }

    public function setNewValue(mixed $newValue): static
    {
        $this->newValue = $newValue;

        return $this;
    }

    public function getChangedBy(): int
    {
        return $this->changedBy;
    }

    public function setChangedBy(int $changedBy): static
    {
        $this->changedBy = $changedBy;

        return $this;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
