<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Moderation\AuthorityNotifications;
use App\Moderation\EscalationAlert;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Suspected illegal content: hide, legal-hold, alert. Does not decide.
 *
 * @see docs/specs/photo-uploads.md §6d
 *
 * @api
 */
final class MediaEscalationService
{
    public const int REASON_MAX = 2000;

    /** Cards per page on the admin's legal-hold list. */
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaEventLog $events,
        private readonly MediaDecisionService $decisions,
        private readonly PhotoGallery $gallery,
        private readonly EscalationAlert $alert,
        private readonly MediaStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws \InvalidArgumentException when the curator gave no reason */
    public function escalate(MediaUpload $upload, User $curator, string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('moderate.escalate.error.reason_required');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new \InvalidArgumentException('moderate.escalate.error.reason_too_long');
        }
        if ($upload->isEscalated()) {
            return;   // idempotent: a double-submit must not re-alert
        }

        $upload->escalate((int) $curator->getId(), $reason);
        $this->detachFromItem($upload);
        $this->events->append($upload->getId(), (int) $curator->getId(), MediaAction::Escalated, $reason);
        $this->em->flush();
        $this->withholdObjects($upload);

        $this->alert->escalated('photo', $upload->getId()->toRfc4122(), $reason, '/admin/escalated');
    }

    /**
     * Lift the hold; do not put the photo back on the map.
     *
     * @throws HeldPhotoNotRestored when the objects could not go back to their
     *                              public key; the hold then stays
     */
    public function release(MediaUpload $upload, User $admin, ?string $note = null): bool
    {
        if (!$upload->isEscalated()) {
            return false;
        }

        $this->restoreObjects($upload);
        $upload->releaseEscalation();
        $this->events->append($upload->getId(), (int) $admin->getId(), MediaAction::EscalationReleased, $note);
        $this->em->flush();

        return true;
    }

    /**
     * Take a held photo's objects out of the public bucket.
     *
     * Its URL was on the map before the hold, so it sits in tiles, caches and
     * logs; hiding the gallery entry alone leaves the bytes one request away.
     * Runs after the hold is flushed, so nothing can delete the row while the
     * objects move. A failed move is logged, never thrown: the hold and the
     * alert must still happen.
     */
    public function withholdObjects(MediaUpload $upload): void
    {
        if (null === $upload->getRevision()) {
            return;   // never published: the bytes are in quarantine, which is private
        }

        try {
            $done = $this->storage->withhold($upload->getStorageBucket(), $upload->getPathPrefix());
        } catch (ShardUnavailable) {
            $done = false;
        }
        if (!$done) {
            $this->logger->critical('A photo under legal hold is still in the public bucket.', ['media' => $upload->getId()->toRfc4122()]);
        }
    }

    /**
     * Put a released photo's objects back under their public key, so every
     * later path (the desk, Trash, the retention sweep) finds them where the
     * row says they are. Still off the map.
     *
     * @throws HeldPhotoNotRestored when the move back is incomplete
     */
    public function restoreObjects(MediaUpload $upload): void
    {
        if (null === $upload->getRevision()) {
            return;
        }

        try {
            $done = $this->storage->unwithhold($upload->getStorageBucket(), $upload->getPathPrefix());
        } catch (ShardUnavailable $e) {
            throw new HeldPhotoNotRestored($upload->getId()->toRfc4122(), $e);
        }
        if (!$done) {
            throw new HeldPhotoNotRestored($upload->getId()->toRfc4122());
        }
    }

    /**
     * One variant of a held photo, for the admin's legal-hold page.
     *
     * @return resource|null
     */
    public function heldStream(MediaUpload $upload, string $variant)
    {
        if (!$upload->isEscalated() || null === $upload->getRevision()) {
            return null;
        }

        return $this->storage->readHeldStream($upload->getPathPrefix(), $variant);
    }

    /**
     * Everything currently held, newest first.
     *
     * Each card carries the DSA Art. 18 record (docs/specs/operations.md §7):
     * when, by whom, which authority and its reference, and whether the hold
     * is overdue for one.
     *
     * @return list<array{uuid: string, reason: string, escalatedAt: \DateTimeImmutable, escalatedBy: string, itemName: string, submissionId: ?int, notifiedAt: ?\DateTimeImmutable, notifiedBy: string, authority: ?string, reference: ?string, overdue: bool}>
     */
    public function held(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.escalatedAt IS NOT NULL
             ORDER BY m.escalatedAt DESC',
        )
            ->setFirstResult(max(0, (max(1, $page) - 1) * max(1, $perPage)))
            ->setMaxResults(max(1, $perPage))
            ->getResult();

        $cards = [];
        foreach ($rows as $upload) {
            $at = $upload->getEscalatedAt();
            if (null === $at) {
                continue;
            }
            $cards[] = [
                'uuid' => $upload->getId()->toRfc4122(),
                'reason' => $upload->getEscalatedReason() ?? '',
                'escalatedAt' => $at,
                'escalatedBy' => $this->displayName($upload->getEscalatedById()),
                'itemName' => $this->itemName($upload),
                'submissionId' => $upload->getSubmissionId(),
                'notifiedAt' => $upload->getAuthorityNotifiedAt(),
                'notifiedBy' => $this->displayName($upload->getAuthorityNotifiedById()),
                'authority' => $upload->getAuthorityName(),
                'reference' => $upload->getAuthorityReference(),
                'overdue' => AuthorityNotifications::isOverdue($at, $upload->getAuthorityNotifiedAt()),
            ];
        }

        return $cards;
    }

    /** How many photos are under hold, for the pager. */
    public function heldCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM '.MediaUpload::class.' m WHERE m.escalatedAt IS NOT NULL',
        )->getSingleScalarResult();
    }

    /** Off the map at once — same sm-URL detach as takedown. */
    private function detachFromItem(MediaUpload $upload): void
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
        $kept = array_values(array_filter(
            $photos,
            fn (mixed $photo): bool => !$this->decisions->isEntryFor($photo, $upload),
        ));
        if ([] === $kept) {
            unset($attributes['photos']);
        } else {
            $attributes['photos'] = $kept;
        }
        $item->setAttributes($attributes);
    }

    private function displayName(?int $userId): string
    {
        return (null !== $userId ? $this->em->find(User::class, $userId) : null)?->getDisplayName() ?? '';
    }

    private function itemName(MediaUpload $upload): string
    {
        return $this->gallery->holderOf($upload)?->getName() ?? '';
    }
}
