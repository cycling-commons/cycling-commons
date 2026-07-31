<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging\Entity;

use App\Messaging\UserMessageKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A dashboard message delivered to a rider or curator: a decision outcome
 * (submission, route, or correction), a free-form curator note, or a
 * rider's needs-info reply. The `user_id` column has a DB-level
 * `ON DELETE CASCADE` foreign key to `users(id)`, but the entity keeps it
 * as a plain int column, matching every other entity's FK convention.
 *
 * @see docs/specs/moderation-and-contribution.md §7
 *
 * @api Read by the messages dashboard; written by moderation decision handlers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_message')]
#[ORM\Index(name: 'idx_user_message_unread', columns: ['user_id', 'read_at'])]
class UserMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: UserMessageKind::class)]
    private UserMessageKind $kind;

    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $sender;

    #[ORM\Column(name: 'sender_id', type: Types::BIGINT, nullable: true)]
    private ?int $senderId;

    #[ORM\Column(type: Types::STRING, length: 12)]
    private string $channel;

    #[ORM\Column(name: 'ref_id', type: Types::BIGINT)]
    private int $refId;

    #[ORM\Column(name: 'ref_label', type: Types::STRING, length: 220)]
    private string $refLabel;

    #[ORM\Column(name: 'body_key', type: Types::STRING, length: 120, nullable: true)]
    private ?string $bodyKey;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'body_params', type: Types::JSON, nullable: true)]
    private ?array $bodyParams;

    #[ORM\Column(name: 'body_text', type: Types::TEXT, nullable: true)]
    private ?string $bodyText;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * Optional reference to one of the submission's photos
     * (docs/specs/photo-uploads.md §5b). A detail of an ordinary message, not a
     * second messaging system — and SET NULL on delete, because disposing of a
     * photo must never delete the conversation about it.
     */
    #[ORM\Column(name: 'media_id', type: 'uuid', nullable: true)]
    private ?Uuid $mediaId;

    /** @param array<string, mixed>|null $bodyParams */
    public function __construct(
        int $userId,
        UserMessageKind $kind,
        string $sender,
        ?int $senderId,
        string $channel,
        int $refId,
        string $refLabel,
        ?string $bodyKey,
        ?array $bodyParams,
        ?string $bodyText,
        ?Uuid $mediaId = null,
    ) {
        $this->userId = $userId;
        $this->kind = $kind;
        $this->sender = $sender;
        $this->senderId = $senderId;
        $this->channel = $channel;
        $this->refId = $refId;
        $this->refLabel = $refLabel;
        $this->bodyKey = $bodyKey;
        $this->bodyParams = $bodyParams;
        $this->bodyText = $bodyText;
        $this->mediaId = $mediaId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markRead(): void
    {
        if (null === $this->readAt) {
            $this->readAt = new \DateTimeImmutable();
        }
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getMediaId(): ?Uuid
    {
        return $this->mediaId;
    }

    public function getKind(): UserMessageKind
    {
        return $this->kind;
    }

    public function getSender(): string
    {
        return $this->sender;
    }

    public function getSenderId(): ?int
    {
        return $this->senderId;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getRefId(): int
    {
        return $this->refId;
    }

    public function getRefLabel(): string
    {
        return $this->refLabel;
    }

    public function getBodyKey(): ?string
    {
        return $this->bodyKey;
    }

    /** @return array<string, mixed>|null */
    public function getBodyParams(): ?array
    {
        return $this->bodyParams;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
