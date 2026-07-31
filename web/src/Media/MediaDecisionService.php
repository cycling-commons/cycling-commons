<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a moderation decision means for the submission's photos
 * (docs/specs/photo-uploads.md §5). Deliberately NOT a second moderation
 * mechanism: it is invoked from inside ModerationService::decide()'s existing
 * transaction, on the existing decision, and the item-side record of the
 * attachment is the existing change_history row — a curator who can moderate a
 * fact edit can moderate a photo without learning anything new
 * (docs/specs/photo-uploads.md §5c).
 *
 * Per-photo decisions run one way only. Approving a submission approves its
 * photos except the ones the curator unticked; rejecting a submission rejects
 * all of them, with no per-photo escape; needs-info leaves them pending,
 * because the rider is still being asked.
 *
 * @api Called by ModerationService.
 */
final class MediaDecisionService
{
    public const string LICENSE = 'CC BY-SA 4.0';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly MediaEventLog $events,
    ) {
    }

    /**
     * @param list<string> $rejectMediaIds uuids the curator unticked
     *
     * @return array{old: mixed, new: list<array<string, mixed>>}|null the
     *                                                                 photos-attribute change for the caller's history row,
     *                                                                 or null when nothing about the item changed
     */
    public function apply(
        Submission $submission,
        ?Item $item,
        string $decision,
        User $curator,
        ?string $note,
        array $rejectMediaIds = [],
    ): ?array {
        if ('needs_info' === $decision) {
            return null;
        }

        $uploads = $this->em->getRepository(MediaUpload::class)->findBy([
            'submissionId' => (int) $submission->getId(),
            'status' => MediaStatus::Pending,
        ], ['createdAt' => 'ASC']);
        if ([] === $uploads) {
            return null;
        }

        $unticked = array_flip(array_map(strval(...), $rejectMediaIds));
        $curatorId = (int) $curator->getId();
        $attached = [];

        foreach ($uploads as $upload) {
            $rejected = 'reject' === $decision || isset($unticked[$upload->getId()->toRfc4122()]);
            if ($rejected) {
                $upload->reject();
                $this->events->append($upload->getId(), $curatorId, MediaAction::Rejected, $note);
                continue;
            }

            $upload->approve($item?->getId());
            $this->events->append($upload->getId(), $curatorId, MediaAction::Approved, $note);
            $attached[] = $this->describe($upload);
        }

        if (null === $item || [] === $attached) {
            return null;
        }

        $attributes = $item->getAttributes();
        $old = $attributes['photos'] ?? ($attributes['photo'] ?? null);

        $gallery = self::existingGallery($attributes);
        // The legacy singular is migrated INTO the gallery rather than left
        // beside it: map.js reads f.photos || [f.photo], so leaving both would
        // silently shadow one of them.
        unset($attributes['photo']);
        $attributes['photos'] = [...$gallery, ...$attached];
        $item->setAttributes($attributes);

        return ['old' => $old, 'new' => $attributes['photos']];
    }

    /** @return array<string, mixed> */
    private function describe(MediaUpload $upload): array
    {
        $prefix = $upload->getPathPrefix();
        $continent = $upload->getContinent();

        $photo = [
            'sm' => $this->storage->url($continent, $prefix, 'sm'),
            'lg' => $this->storage->url($continent, $prefix, 'lg'),
            'credit' => $this->credit($upload),
            'license' => self::LICENSE,
        ];
        // Month granularity: public seasonal context, never a precise timestamp
        // (docs/specs/photo-uploads.md §5). No capture date means no key at all.
        $takenAt = $upload->getTakenAt();
        if (null !== $takenAt) {
            $photo['takenAt'] = $takenAt->format('Y-m');
        }

        return $photo;
    }

    /** The existing uploader rule: a name only when the rider chose to be public. */
    private function credit(MediaUpload $upload): string
    {
        $userId = $upload->getUserId();
        if (null === $userId) {
            return '';
        }
        $user = $this->em->find(User::class, $userId);

        return (null !== $user && $user->isPublicProfile()) ? $user->getDisplayName() : '';
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<array<string, mixed>>
     */
    private static function existingGallery(array $attributes): array
    {
        $photos = $attributes['photos'] ?? null;
        if (\is_array($photos)) {
            return array_values(array_filter($photos, is_array(...)));
        }
        $single = $attributes['photo'] ?? null;

        return \is_array($single) ? [$single] : [];
    }
}
