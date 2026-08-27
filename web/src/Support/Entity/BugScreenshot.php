<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A picture of the bug, stored in the database rather than in a bucket.
 *
 * **Why not the media pipeline.** {@see \App\Media\MediaUpload} is built for
 * rider photographs of places: consent records, licence grants, EXIF stripping,
 * quarantine, a virus scan, per-continent buckets, a curator queue, and a
 * public URL at the end of it. A screenshot of a broken button needs none of
 * that and must never get a public URL. Pushing it through that pipeline would
 * mean a bug report could end up in the Commons.
 *
 * **Why not the local disk.** Production runs two front ends behind a load
 * balancer with one shared database (see docs/specs/deployment.md). A
 * screenshot written to disk on one front end is a broken image on the other,
 * roughly half the time, which is the kind of bug this table exists to collect.
 *
 * So: bytes in a column. The volume makes it reasonable. A re-encoded
 * screenshot is a couple of hundred kilobytes, at most three per report, and
 * bug reports arrive at human speed. They are re-encoded through Imagick on the
 * way in ({@see \App\Support\ScreenshotStore}), which is what makes them safe:
 * whatever was in the original file, what is stored is pixels this server drew.
 * They are served only to curators, only through a controller, and only with
 * `Content-Disposition: attachment`.
 *
 * @see docs/specs/contact-and-support.md §6
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'bug_screenshot')]
class BugScreenshot
{
    /** After re-encoding, not before. The upload cap is checked separately. */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BugReport::class, inversedBy: 'screenshots')]
    #[ORM\JoinColumn(name: 'report_id', nullable: false, onDelete: 'CASCADE')]
    private ?BugReport $report = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position = 0;

    /** Always what we re-encoded to, never what was uploaded. */
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

    public function __construct(string $mimeType, string $bytes, int $width, int $height)
    {
        $this->mimeType = $mimeType;
        $this->bytes = $bytes;
        $this->byteSize = \strlen($bytes);
        $this->width = $width;
        $this->height = $height;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReport(): ?BugReport
    {
        return $this->report;
    }

    public function attachTo(BugReport $report, int $position): void
    {
        $this->report = $report;
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
     * The image bytes.
     *
     * Doctrine hands a BLOB back as a stream resource on read and as the
     * original string on the write that created it, so both are handled here
     * rather than at every call site.
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
