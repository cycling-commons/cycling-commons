<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\ConfirmationSource;
use App\Catalog\ConfirmationStance;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One rider's community stance on a non-votable item (drinking-water
 * potability or a plain existence confirmation for a utility). One row per
 * user per item (UNIQUE), and changeable: a rider may switch between
 * potable and not potable. The map drawer tallies these; the counts are
 * public, recording requires an account.
 *
 * @see docs/specs/moderation-and-contribution.md §10.1
 *
 * @api Created and updated by ItemConfirmationService.
 */
#[ORM\Entity]
#[ORM\Table(name: 'item_confirmation')]
#[ORM\UniqueConstraint(name: 'uniq_item_confirmation', columns: ['item_id', 'user_id'])]
#[ORM\Index(name: 'idx_item_confirmation_tally', columns: ['item_id', 'stance'])]
class ItemConfirmation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'item_id', type: Types::BIGINT)]
    private int $itemId;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ConfirmationStance::class)]
    private ConfirmationStance $stance;

    /**
     * The rider's "why", offered only with a negative stance (a surface
     * segment's "not as described" — owner 2026-08-13). Free text FOR THE
     * CURATORS: it is never rendered publicly, because public free text
     * without moderation would be a second, unmoderated publishing channel
     * (one-way-to-moderate). Null for every positive answer.
     */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $note = null;

    /**
     * Where the answer came from. A form-sourced row is the submitter's own
     * answer on the improve form: kept so they are never asked it again, but
     * left out of the tally and the verified derivation, because it is the
     * claim rather than a confirmation of it (ConfirmationSource).
     */
    #[ORM\Column(type: Types::STRING, length: 8, enumType: ConfirmationSource::class, options: ['default' => 'drawer'])]
    private ConfirmationSource $source;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $itemId, int $userId, ConfirmationStance $stance, ConfirmationSource $source = ConfirmationSource::Drawer)
    {
        $this->itemId = $itemId;
        $this->userId = $userId;
        $this->stance = $stance;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getStance(): ConfirmationStance
    {
        return $this->stance;
    }

    public function setStance(ConfirmationStance $stance): static
    {
        $this->stance = $stance;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSource(): ConfirmationSource
    {
        return $this->source;
    }

    /**
     * Answering in the drawer promotes a form-sourced row: the rider has now
     * confirmed the place as a rider, so it starts counting. It never goes the
     * other way — a real confirmation is not demoted by a later form edit.
     */
    public function setSource(ConfirmationSource $source): static
    {
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();

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
