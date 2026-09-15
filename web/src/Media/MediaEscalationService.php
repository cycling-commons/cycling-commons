<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Moderation\EscalationAlert;
use Doctrine\ORM\EntityManagerInterface;

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

        $this->alert->escalated('photo', $upload->getId()->toRfc4122(), $reason, '/admin/escalated');
    }

    /** Lift the hold; do not put the photo back on the map. */
    public function release(MediaUpload $upload, User $admin, ?string $note = null): bool
    {
        if (!$upload->isEscalated()) {
            return false;
        }

        $upload->releaseEscalation();
        $this->events->append($upload->getId(), (int) $admin->getId(), MediaAction::EscalationReleased, $note);
        $this->em->flush();

        return true;
    }

    /**
     * Everything currently held, newest first.
     *
     * @return list<array{uuid: string, sm: string, reason: string, escalatedAt: \DateTimeImmutable, escalatedBy: string, itemName: string}>
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
            $by = $upload->getEscalatedById();
            $curator = null !== $by ? $this->em->find(User::class, $by) : null;
            $cards[] = [
                'uuid' => $upload->getId()->toRfc4122(),
                'sm' => (string) $this->decisions->describe($upload)['sm'],
                'reason' => $upload->getEscalatedReason() ?? '',
                'escalatedAt' => $at,
                'escalatedBy' => $curator?->getDisplayName() ?? '',
                'itemName' => $this->itemName($upload),
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

    private function itemName(MediaUpload $upload): string
    {
        return $this->gallery->holderOf($upload)?->getName() ?? '';
    }
}
