<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Moderation\RetentionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * End-of-life for photo bytes: orphans, rejects, trash, anonymize.
 *
 * @see docs/specs/photo-uploads.md §6
 *
 * @api
 */
final class MediaDisposalService
{
    /** Unclaimed pending uploads older than this are orphans. @see docs/specs/photo-uploads.md §6 */
    public const int ORPHAN_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly MediaDecisionService $decisions,
        private readonly PhotoGallery $gallery,
        private readonly MediaEventLog $events,
        private readonly RetentionService $retention,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Objects, row and log. Silent no-op under legal hold.
     *
     * @see docs/specs/photo-uploads.md §6d
     */
    public function purge(MediaUpload $upload): void
    {
        if ($upload->isEscalated()) {
            $this->logger->warning('Refused to purge a photo under legal hold.', ['media' => $upload->getId()->toRfc4122()]);

            return;
        }
        $this->destroyObjects($upload);
        $this->em->remove($upload);   // media_moderation_event cascades
    }

    /** Objects only: the row survives as a tombstone. */
    public function deleteObjects(MediaUpload $upload): void
    {
        if (null !== $upload->getObjectsDeletedAt()) {
            return;
        }
        if ($upload->isEscalated()) {
            $this->logger->warning('Refused to delete the objects of a photo under legal hold.', ['media' => $upload->getId()->toRfc4122()]);

            return;
        }
        $this->destroyObjects($upload);
        $upload->markObjectsDeleted();
        $this->events->append($upload->getId(), null, MediaAction::ObjectsDeleted);
    }

    /** Quarantine always; published prefix only if a revision exists. @see docs/specs/media-storage-architecture.md §2 */
    private function destroyObjects(MediaUpload $upload): void
    {
        $this->storage->deleteQuarantine($upload->getQuarantineKey());
        if ($upload->hasPublishedObjects()) {
            $this->storage->deletePrefix($upload->getStorageBucket(), $upload->getPathPrefix());
        }
    }

    public function collectOrphans(): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', self::ORPHAN_DAYS));

        /** @var list<MediaUpload> $orphans */
        $orphans = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.status IN (:pending) AND m.submissionId IS NULL AND m.routeId IS NULL AND m.createdAt < :cutoff',
        )
            ->setParameter('pending', [MediaStatus::Pending, MediaStatus::PendingScan])
            ->setParameter('cutoff', $cutoff)
            ->getResult();

        foreach ($orphans as $orphan) {
            $this->purge($orphan);
        }
        $this->em->flush();

        return \count($orphans);
    }

    public function collectRejected(): int
    {
        $cutoff = $this->retention->cutoff();

        /** @var list<MediaUpload> $expired */
        $expired = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.status = :rejected AND m.objectsDeletedAt IS NULL AND m.decidedAt < :cutoff',
        )
            ->setParameter('rejected', MediaStatus::Rejected)
            ->setParameter('cutoff', $cutoff)
            ->getResult();

        foreach ($expired as $upload) {
            $this->deleteObjects($upload);
        }
        $this->em->flush();

        return \count($expired);
    }

    /** Trash: everything now, in the caller's transaction. @see docs/specs/photo-uploads.md §6 */
    public function purgeForSubmission(int $submissionId): int
    {
        $uploads = $this->em->getRepository(MediaUpload::class)->findBy(['submissionId' => $submissionId]);
        foreach ($uploads as $upload) {
            $this->purge($upload);
        }

        return \count($uploads);
    }

    /**
     * Trash of a route proposal (`$suggestionId` null: the photos sent with
     * the proposal) or of one photo correction: everything now, in the
     * caller's transaction.
     *
     * @see docs/specs/photo-uploads.md §5i
     */
    public function purgeForRoute(int $routeId, ?int $suggestionId): int
    {
        $uploads = $this->em->getRepository(MediaUpload::class)->findBy(['routeId' => $routeId, 'routeSuggestionId' => $suggestionId]);
        foreach ($uploads as $upload) {
            $this->purge($upload);
        }

        return \count($uploads);
    }

    /** Account deletion: drop unmoderated work; anonymize approved credit. @see docs/specs/photo-uploads.md §6 */
    public function anonymizeFor(User $user): void
    {
        $userId = (int) $user->getId();
        $uploads = $this->em->getRepository(MediaUpload::class)->findBy(['userId' => $userId]);

        // Preserve credit only if it was already public. @see docs/specs/photo-uploads.md §6
        $keepCredit = $user->isKeepMediaCredit() && $user->isPublicProfile();
        $frozen = $keepCredit ? $user->getDisplayName() : '';

        foreach ($uploads as $upload) {
            if (MediaStatus::Approved !== $upload->getStatus()) {
                $this->purge($upload);
                continue;
            }

            $upload->anonymize($frozen);
            if (!$keepCredit) {
                $this->events->append($upload->getId(), null, MediaAction::CreditAnonymized);
                $this->clearCredit($upload);
            }
        }
    }

    /** Clear gallery credit matched on the sm URL. */
    private function clearCredit(MediaUpload $upload): void
    {
        $item = $this->gallery->holderOf($upload);
        if (null === $item) {
            return;
        }

        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }

        $changed = false;
        foreach ($photos as $index => $photo) {
            if ($this->decisions->isEntryFor($photo, $upload) && '' !== ($photo['credit'] ?? '')) {
                $photos[$index]['credit'] = '';
                $changed = true;
            }
        }

        if ($changed) {
            $attributes['photos'] = $photos;
            $item->setAttributes($attributes);
        }
    }
}
