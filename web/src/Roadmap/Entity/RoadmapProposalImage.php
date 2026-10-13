<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap\Entity;

use App\Support\CheckedPicture;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The one picture a rider may add to a roadmap idea: a sketch, a screenshot
 * of what they mean. Stored in the database and checked on the worker, the
 * same way and for the same reasons as a bug screenshot
 * (App\Support\Entity\BugScreenshot): held raw as `pending`, scanned and drawn
 * again by App\Support\MessageHandler\CheckPictureHandler, served once
 * `ready`, and only through a controller.
 *
 * It is seen by the rider who sent it and the desk. It shows on a public page
 * only when both said yes: the rider, who allowed it when sending
 * (`consented_at`, the moment they ticked the box), and the desk, which
 * chose to show it (`shown`). It is the rider's picture; the permission is
 * to show it with their proposal, nothing more.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'roadmap_proposal_image')]
#[ORM\UniqueConstraint(name: 'uniq_roadmap_proposal_image', columns: ['proposal_id'])]
class RoadmapProposalImage
{
    use CheckedPicture;

    /** After re-encoding, not before. The upload cap is ScreenshotStore::MAX_UPLOAD_BYTES. */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoadmapProposal::class)]
    #[ORM\JoinColumn(name: 'proposal_id', nullable: false, onDelete: 'CASCADE')]
    private RoadmapProposal $proposal;

    /** Always what we re-encoded to, never what was uploaded. */
    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 40)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column(type: Types::BLOB)]
    private mixed $bytes = '';

    #[ORM\Column(name: 'byte_size', type: Types::INTEGER)]
    private int $byteSize = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $width = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $height = 0;

    /** When the rider allowed us to show it with their proposal; null: they did not. */
    #[ORM\Column(name: 'consented_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consentedAt = null;

    /** The desk chose to show it; only possible with the rider's permission. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $shown = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** The raw bytes as sent, held for the worker and never served. */
    public static function pending(RoadmapProposal $proposal, string $raw, bool $mayShow = false): self
    {
        $image = new self($proposal);
        $image->holdRaw($raw);
        $image->consentedAt = $mayShow ? new \DateTimeImmutable() : null;

        return $image;
    }

    private function __construct(RoadmapProposal $proposal)
    {
        $this->proposal = $proposal;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProposal(): RoadmapProposal
    {
        return $this->proposal;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /** The image bytes; Doctrine reads a BLOB back as a stream. */
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

    public function getConsentedAt(): ?\DateTimeImmutable
    {
        return $this->consentedAt;
    }

    public function mayBeShown(): bool
    {
        return null !== $this->consentedAt;
    }

    public function isShown(): bool
    {
        return $this->shown;
    }

    /** The desk's choice; refused without the rider's permission. */
    public function show(bool $shown): void
    {
        \assert(!$shown || $this->mayBeShown());
        $this->shown = $shown;
    }

    /** Public right now: allowed, chosen, and drawn by the worker. */
    public function isPublic(): bool
    {
        return $this->shown && $this->mayBeShown() && $this->isReady();
    }
}
