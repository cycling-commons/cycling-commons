<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Moderation\RetentionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * What happens to a photo's bytes at the end of each path
 * (docs/specs/photo-uploads.md §6). The disposal CLASSES belong to
 * docs/specs/moderation-and-contribution.md — one source of truth; this service
 * only carries out what they mean for objects and rows, plus the one
 * media-only class:
 *
 *  - Orphans: pending, never claimed, older than seven days. Nothing was ever
 *    moderated, so objects, row and log all go — a log with no row would be
 *    litter, not history.
 *  - Rejected: once the standard retention window lapses, the objects go and
 *    the row stays as an audit tombstone, its log intact.
 *  - Trashed: objects, row and log go IMMEDIATELY, synchronously with the
 *    Trash action. No window means no sweep, and Trash's content-free
 *    principle means no exceptions — this is the deliberate exception to
 *    "events survive forever".
 *
 * Every method is idempotent: the sweeps run from a cron, the Trash arm from a
 * curator, and the anonymizer from an account deletion, and none of them may
 * fail because somebody else already did the work.
 *
 * @api Called by MediaGcCommand, ModerationService::trashSubmission() and MediaDeletionHook.
 */
final class MediaDisposalService
{
    /** docs/specs/photo-uploads.md §6: nothing to moderate ever arrived. */
    public const int ORPHAN_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly MediaEventLog $events,
        private readonly RetentionService $retention,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Objects, row and log. Used by orphan collection, Trash and account
     * deletion.
     *
     * **Refuses anything under legal hold** (docs/specs/photo-uploads.md §6d).
     * This is the single chokepoint every destructive path runs through, which
     * is why the guard lives here rather than being repeated at each caller:
     * a hold that any one forgotten path could bypass is not a hold. Silent
     * rather than throwing — Trash sweeps a whole submission, and one held
     * photo must not abort the rest or leak its existence through an error.
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

    /** Objects only: the row survives as a tombstone, and so does its history. */
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

    /**
     * Every object this upload owns, in BOTH buckets
     * (docs/specs/media-storage-architecture.md §2).
     *
     * The quarantine delete is unconditional and the published delete is not,
     * and that asymmetry is the point: a row that never released has no
     * published prefix to ask for - getPathPrefix() would throw - while a row
     * that did release may still be holding quarantined bytes if the worker
     * died between the write and the cleanup. Deleting from a bucket with
     * nothing in it costs one no-op; forgetting a bucket leaves a rider's
     * unscanned file behind after they asked us to destroy it.
     *
     * Hetzner Object Storage has no versioning (§2.3), so these deletes are
     * real and immediate. Every caller above already treats them that way.
     */
    private function destroyObjects(MediaUpload $upload): void
    {
        $this->storage->deleteQuarantine($upload->getQuarantineKey());
        if ($upload->hasPublishedObjects()) {
            $this->storage->deletePrefix($upload->getStorageShard(), $upload->getPathPrefix());
        }
    }

    public function collectOrphans(): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', self::ORPHAN_DAYS));

        /** @var list<MediaUpload> $orphans */
        $orphans = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.status IN (:pending) AND m.submissionId IS NULL AND m.createdAt < :cutoff',
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

    /**
     * Trash (docs/specs/photo-uploads.md §6): everything, now, with no trace.
     * Runs inside the caller's transaction so it commits with the Trash audit
     * row or not at all.
     */
    public function purgeForSubmission(int $submissionId): int
    {
        $uploads = $this->em->getRepository(MediaUpload::class)->findBy(['submissionId' => $submissionId]);
        foreach ($uploads as $upload) {
            $this->purge($upload);
        }

        return \count($uploads);
    }

    /**
     * Account deletion (docs/specs/photo-uploads.md §6): unmoderated and
     * rejected work leaves with the account; an approved photo is a CC
     * BY-SA-licensed contribution to the commons and stays, with its credit
     * falling back to anonymous — the same reasoning as anonymized ballots.
     */
    public function anonymizeFor(User $user): void
    {
        $userId = (int) $user->getId();
        $uploads = $this->em->getRepository(MediaUpload::class)->findBy(['userId' => $userId]);

        // The departing rider's own choice, made on the delete-account
        // confirmation (docs/specs/photo-uploads.md §6). It is meaningful
        // precisely because stored files carry a link and not a name: whichever
        // way this goes, it reaches every copy that ever left this site.
        //
        // Gated on the profile being public, and NOT only in the template that
        // offers the checkbox. A rider with a private profile has never been
        // named on their photos; honouring a stale ticked box would publish a
        // name at the exact moment they are leaving, which is the opposite of
        // what either setting means. Deletion may only preserve a credit that
        // was already visible — it may never create one.
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

    /**
     * Rewrites the visible credit on the item this photo is attached to. The
     * photo is matched by the URL the attachment was built from, which carries
     * the upload's uuid — an exact, deterministic match, not a guess.
     */
    private function clearCredit(MediaUpload $upload): void
    {
        $itemId = $upload->getItemId();
        if (null === $itemId) {
            return;
        }
        $item = $this->em->find(Item::class, $itemId);
        if (null === $item) {
            return;
        }

        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }

        $target = $this->storage->url($upload->getStorageShard(), $upload->getPathPrefix(), 'sm');
        $changed = false;
        foreach ($photos as $index => $photo) {
            if (\is_array($photo) && ($photo['sm'] ?? null) === $target && '' !== ($photo['credit'] ?? '')) {
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
