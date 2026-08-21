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
 * Apply a submission decision to its photos — not a second moderation path.
 *
 * @see docs/specs/photo-uploads.md §5, §5c
 *
 * @api
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
     * @param list<string> $rejectMediaIds
     *
     * @return array{old: mixed, new: list<array<string, mixed>>}|null
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
        // Skip legal hold and unpublished rows. @see docs/specs/photo-uploads.md §6d
        $uploads = array_values(array_filter(
            $uploads,
            static fn (MediaUpload $u): bool => !$u->isEscalated() && $u->hasPublishedObjects(),
        ));
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
        // Migrate the legacy singular into photos[]; map.js would shadow one if both remain.
        unset($attributes['photo']);
        $attributes['photos'] = [...$gallery, ...$attached];
        $item->setAttributes($attributes);

        return ['old' => $old, 'new' => $attributes['photos']];
    }

    /**
     * Gallery entry written at approval (takedown reuses this shape).
     *
     * @return array<string, mixed>
     *
     * @see docs/specs/photo-uploads.md §6b
     *
     * @api
     */
    public function describe(MediaUpload $upload): array
    {
        $prefix = $upload->getPathPrefix();
        $bucket = $upload->getStorageBucket();

        $photo = [
            // The upload's own id, so a gallery entry can be matched back to its
            // row without comparing URLs. The URLs move when the key layout or
            // the bucket name does (both have), and every gallery mutation used
            // to match on `sm` - a takedown against a moved photo then matched
            // nothing and silently left the image on the item. The uuid is
            // already visible inside the URL, so publishing it adds nothing.
            'id' => $upload->getId()->toRfc4122(),
            'sm' => $this->storage->url($bucket, $prefix, 'sm'),
            'lg' => $this->storage->url($bucket, $prefix, 'lg'),
            'credit' => $this->credit($upload),
            'license' => self::LICENSE,
        ];
        // Month granularity only. @see docs/specs/photo-uploads.md §5
        $takenAt = $upload->getTakenAt();
        if (null !== $takenAt) {
            $photo['takenAt'] = $takenAt->format('Y-m');
        }

        return $photo;
    }

    /**
     * Is this gallery entry the one this upload wrote?
     *
     * The single place that answers it, so takedown, escalation and disposal
     * can never drift apart. Prefers the recorded id; falls back to the `sm`
     * URL for entries written before the id existed. That fallback goes once
     * `app:media:repair-galleries` has run everywhere - it is a migration
     * bridge, not a shape to tolerate.
     *
     * @see docs/specs/photo-uploads.md §6b
     *
     * @api
     */
    public function isEntryFor(mixed $photo, MediaUpload $upload): bool
    {
        if (!\is_array($photo)) {
            return false;
        }

        $id = $photo['id'] ?? null;
        if (\is_string($id) && '' !== $id) {
            return $id === $upload->getId()->toRfc4122();
        }

        return ($photo['sm'] ?? null) === $this->describe($upload)['sm'];
    }

    /** Name only when the rider chose a public profile. */
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
