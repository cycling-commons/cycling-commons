<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The pending, ready or refused life of a picture a person sent us, shared by
 * a bug report's screenshot and a curator-room image.
 *
 * The web host only holds the raw bytes ({@see holdRaw()}) and queues a
 * {@see Message\CheckPicture}; the worker scans them and draws them again
 * ({@see markReady()}), or refuses them ({@see markRefused()}), which drops
 * the bytes. Nothing but a ready picture is ever served. The using class
 * declares `$mimeType`, `$bytes`, `$byteSize`, `$width` and `$height`.
 *
 * @see docs/specs/contact-and-support.md §6
 */
trait CheckedPicture
{
    #[ORM\Column(type: Types::STRING, length: 12, enumType: PictureState::class, options: ['default' => 'ready'])]
    private PictureState $state = PictureState::Ready;

    /** A translation key: why the worker would not keep it. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $refusal = null;

    private function holdRaw(string $raw): void
    {
        $this->state = PictureState::Pending;
        $this->mimeType = 'application/octet-stream';
        $this->bytes = $raw;
        $this->byteSize = \strlen($raw);
        $this->width = 0;
        $this->height = 0;
    }

    public function markReady(StoredImage $image): void
    {
        $this->state = PictureState::Ready;
        $this->refusal = null;
        $this->mimeType = $image->mimeType;
        $this->bytes = $image->bytes;
        $this->byteSize = \strlen($image->bytes);
        $this->width = $image->width;
        $this->height = $image->height;
    }

    public function markRefused(string $reason): void
    {
        $this->state = PictureState::Refused;
        $this->refusal = $reason;
        $this->mimeType = 'application/octet-stream';
        $this->bytes = '';
        $this->byteSize = 0;
        $this->width = 0;
        $this->height = 0;
    }

    public function getState(): PictureState
    {
        return $this->state;
    }

    public function isReady(): bool
    {
        return PictureState::Ready === $this->state;
    }

    public function isPending(): bool
    {
        return PictureState::Pending === $this->state;
    }

    public function getRefusal(): ?string
    {
        return $this->refusal;
    }
}
