<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One lifecycle transition of one photo. The media-scoped twin of the item-side
 * change_history: same append-only conventions, same (subject, time) index
 * (docs/specs/photo-uploads.md §5b). media_upload.status answers "what is it
 * now"; this log answers "how did it get here".
 *
 * The foreign key cascades on delete because the two purges that remove a
 * media_upload row are exactly the two cases where nothing may survive: Trash
 * (content-free by principle) and orphan collection (nothing was ever
 * moderated). A rejected row past retention is tombstoned, not deleted, so its
 * log survives.
 *
 * @api Media domain entity; written by MediaEventLog.
 */
#[ORM\Entity]
#[ORM\Table(name: 'media_moderation_event')]
#[ORM\Index(name: 'idx_media_event_time', columns: ['media_id', 'created_at'])]
class MediaModerationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'media_id', type: 'uuid')]
    private Uuid $mediaId;

    /** Null = the system acted (garbage collection, deletion hook). */
    #[ORM\Column(name: 'actor_id', type: Types::BIGINT, nullable: true)]
    private ?int $actorId;

    /** One of {@see \App\Media\MediaAction}. */
    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $action;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $mediaId, ?int $actorId, string $action, ?string $note = null)
    {
        $this->mediaId = $mediaId;
        $this->actorId = $actorId;
        $this->action = $action;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMediaId(): Uuid
    {
        return $this->mediaId;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
