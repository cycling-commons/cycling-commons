<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging\Entity;

use App\Support\StoredImage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One image on a curator-room post.
 *
 * Stored in the database and served to curators only, the way a bug report's
 * screenshot is: the rulebook says pending content never leaves the desk, and
 * a picture in a public bucket has left it. The bytes are what
 * {@see \App\Support\ScreenshotStore} drew, never the uploaded file.
 *
 * A picture uploads before its post exists, so the composer can show real
 * progress; it waits with `post_id` NULL until the post claims it, and only
 * its uploader may claim it. Unclaimed pictures are swept after a day.
 * `post_id` is ON DELETE CASCADE: a post's images go with the post.
 *
 * @see docs/specs/moderation-and-contribution.md §13.3
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_post_image')]
#[ORM\Index(name: 'idx_curator_post_image_post', columns: ['post_id', 'position'])]
class CuratorPostImage
{
    /** After re-encoding. A photograph as WebP at 2000 px sits well under this. */
    public const int MAX_BYTES = 3 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CuratorPost::class, inversedBy: 'images')]
    #[ORM\JoinColumn(name: 'post_id', nullable: true, onDelete: 'CASCADE')]
    private ?CuratorPost $post = null;

    #[ORM\Column(name: 'uploader_id', type: Types::BIGINT, nullable: true)]
    private ?int $uploaderId;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 40)]
    private string $mimeType;

    #[ORM\Column(type: Types::BLOB)]
    private mixed $bytes;

    #[ORM\Column(name: 'byte_size', type: Types::INTEGER)]
    private int $byteSize;

    #[ORM\Column(type: Types::INTEGER)]
    private int $width;

    #[ORM\Column(type: Types::INTEGER)]
    private int $height;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(StoredImage $image, ?int $uploaderId)
    {
        $this->uploaderId = $uploaderId;
        $this->mimeType = $image->mimeType;
        $this->bytes = $image->bytes;
        $this->byteSize = \strlen($image->bytes);
        $this->width = $image->width;
        $this->height = $image->height;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): ?CuratorPost
    {
        return $this->post;
    }

    public function getUploaderId(): ?int
    {
        return $this->uploaderId;
    }

    public function isClaimed(): bool
    {
        return null !== $this->post;
    }

    public function attachTo(CuratorPost $post, int $position): void
    {
        $this->post = $post;
        $this->position = $position;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Doctrine hands a BLOB back as a stream on read and as the string on the
     * write that created it, so both are handled here.
     */
    public function getBytes(): string
    {
        if (\is_resource($this->bytes)) {
            rewind($this->bytes);

            return (string) stream_get_contents($this->bytes);
        }

        return (string) $this->bytes;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
