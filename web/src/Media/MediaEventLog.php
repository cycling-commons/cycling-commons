<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Media\Entity\MediaModerationEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one writer of media_moderation_event (docs/specs/photo-uploads.md §5b).
 * Persists without flushing so an event always lands inside its caller's
 * transaction — the same discipline ModerationService uses for change_history.
 *
 * @api Called by every service that changes a MediaUpload's state.
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
