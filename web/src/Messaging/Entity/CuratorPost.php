<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging\Entity;

use App\Messaging\CuratorRoomCategory;
use App\Messaging\CuratorRoomPin;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One post on the curator room's board.
 *
 * A board row, not an inbox row: `user_message` stores one row per recipient,
 * which would mean one copy per curator for a post addressed to everybody and
 * an edit per copy for a pinned one.
 *
 * `author_id` is ON DELETE SET NULL, so the room's record of a decision
 * survives the author's account. `recipient_id` is ON DELETE CASCADE, because a
 * direct message to a deleted account has no second reader.
 *
 * @see docs/specs/moderation-and-contribution.md §13.3
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_post')]
#[ORM\Index(name: 'idx_curator_post_pin', columns: ['pin', 'created_at'])]
#[ORM\Index(name: 'idx_curator_post_recipient', columns: ['recipient_id', 'created_at'])]
class CuratorPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'author_id', type: Types::BIGINT, nullable: true)]
    private ?int $authorId;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: CuratorRoomCategory::class)]
    private ?CuratorRoomCategory $category;

    #[ORM\Column(name: 'recipient_id', type: Types::BIGINT, nullable: true)]
    private ?int $recipientId;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: CuratorRoomPin::class)]
    private CuratorRoomPin $pin = CuratorRoomPin::None;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'about_submission_id', type: Types::BIGINT, nullable: true)]
    private ?int $aboutSubmissionId;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'edited_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    public function __construct(
        ?int $authorId,
        ?CuratorRoomCategory $category,
        ?int $recipientId,
        string $body,
        ?int $aboutSubmissionId = null,
    ) {
        $this->authorId = $authorId;
        $this->category = $category;
        $this->recipientId = $recipientId;
        $this->body = $body;
        $this->aboutSubmissionId = $aboutSubmissionId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthorId(): ?int
    {
        return $this->authorId;
    }

    public function getCategory(): ?CuratorRoomCategory
    {
        return $this->category;
    }

    public function getRecipientId(): ?int
    {
        return $this->recipientId;
    }

    public function isDirect(): bool
    {
        return null !== $this->recipientId;
    }

    public function getPin(): CuratorRoomPin
    {
        return $this->pin;
    }

    public function setPin(CuratorRoomPin $pin): void
    {
        $this->pin = $pin;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function rewrite(string $body): void
    {
        $this->body = $body;
        $this->editedAt = new \DateTimeImmutable();
    }

    public function getAboutSubmissionId(): ?int
    {
        return $this->aboutSubmissionId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }
}
