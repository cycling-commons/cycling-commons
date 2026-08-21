<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Media\Entity\MediaModerationEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sole writer of media_moderation_event. Persists without flushing.
 *
 * @see docs/specs/photo-uploads.md §5b
 *
 * @api
 */
final class MediaEventLog
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function append(Uuid $mediaId, ?int $actorId, string $action, ?string $note = null): void
    {
        $this->em->persist(new MediaModerationEvent($mediaId, $actorId, $action, $note));
    }
}
