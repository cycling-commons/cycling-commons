<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\ConfirmationStance;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One rider's community stance on a non-votable item (drinking-water potability
 * or a plain existence confirmation for a utility). One row per user per item
 * (UNIQUE), changeable — a rider may switch potable ↔ not potable. The map
 * drawer tallies these; the counts are public, recording requires an account.
 *
 * @api Created/updated by ItemConfirmationService.
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

    #[ORM\Column(type: Types::STRING, length: 12, enumType: ConfirmationStance::class)]
    private ConfirmationStance $stance;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $itemId, int $userId, ConfirmationStance $stance)
    {
        $this->itemId = $itemId;
        $this->userId = $userId;
        $this->stance = $stance;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
