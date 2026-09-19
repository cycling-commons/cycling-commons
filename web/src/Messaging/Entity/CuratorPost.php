<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging\Entity;

use App\Messaging\CuratorRoomCategory;
use App\Messaging\CuratorRoomPin;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    /** Pictures per post. A question needs one or two; a gallery is not a question. */
    public const int MAX_IMAGES = 4;

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

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'about_submission_id', type: Types::BIGINT, nullable: true)]
    private ?int $aboutSubmissionId;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'edited_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    /** @var Collection<int, CuratorPostImage> */
    #[ORM\OneToMany(targetEntity: CuratorPostImage::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $images;

    public function __construct(
        ?int $authorId,
        ?CuratorRoomCategory $category,
        ?int $recipientId,
        string $body,
        ?int $aboutSubmissionId = null,
        string $title = '',
    ) {
        $this->authorId = $authorId;
        $this->category = $category;
        $this->recipientId = $recipientId;
        $this->title = $title;
        $this->body = $body;
        $this->aboutSubmissionId = $aboutSubmissionId;
        $this->createdAt = new \DateTimeImmutable();
        $this->images = new ArrayCollection();
    }

    /** @return Collection<int, CuratorPostImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(CuratorPostImage $image): void
    {
        if ($this->images->count() >= self::MAX_IMAGES) {
            throw new \InvalidArgumentException('room.error.too_many_images');
        }
        $image->attachTo($this, $this->images->count());
        $this->images->add($image);
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Every field the composer set, set again. Stamps `edited_at` only when
     * something actually differs, so a save that changes nothing is not an
     * edit the card has to announce.
     */
    public function update(?CuratorRoomCategory $category, ?int $recipientId, string $body, ?int $aboutSubmissionId, CuratorRoomPin $pin, string $title = ''): void
    {
        $changed = $category !== $this->category || $recipientId !== $this->recipientId
            || $body !== $this->body || $aboutSubmissionId !== $this->aboutSubmissionId || $pin !== $this->pin
            || $title !== $this->title;
        $this->category = $category;
        $this->recipientId = $recipientId;
        $this->title = $title;
        $this->body = $body;
        $this->aboutSubmissionId = $aboutSubmissionId;
        $this->pin = $pin;
        if ($changed) {
            $this->editedAt = new \DateTimeImmutable();
        }
    }

    /** A picture taken off the post: the row goes (orphanRemoval), the rest close ranks. */
    public function removeImage(CuratorPostImage $image): void
    {
        if (!$this->images->removeElement($image)) {
            return;
        }
        $i = 0;
        foreach ($this->images as $rest) {
            $rest->attachTo($this, $i++);
        }
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
