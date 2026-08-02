<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Moderation\EscalationAlert;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Escalation: the third verb, for suspected illegal content
 * (docs/specs/photo-uploads.md §6d).
 *
 * Curators had two verbs and neither fits this case. **Reject** leaves the
 * material sitting in the queue where the next curator meets it too.
 * **Trash** deletes it immediately and completely — content, objects and
 * history — which is exactly backwards where the law expects the material to
 * survive until it has been reported: EU DSA Art. 18 requires promptly
 * informing law enforcement of a suspected offence involving a threat to life
 * or safety, and the terrorist-content regulation carries an explicit
 * six-month preservation duty. Destroying the evidence first is not a
 * conservative choice; it is destroying the evidence.
 *
 * So escalation does four things and refuses to do a fifth:
 *
 * 1. **Hides it from everyone.** Off the map, off the photo page, and out of
 *    the moderation desk — the curator who escalated it does not have to see
 *    it again, and no other curator ever sees it. Only an admin can, in the
 *    admin area.
 * 2. **Puts a legal hold on the row.** Nothing may destroy it: not Trash, not
 *    the retention sweep, not orphan collection, not a bulk restore.
 * 3. **Tells a human immediately**, unthrottled, one mail per escalation.
 * 4. **Records who escalated it, when, and in whose words**, which is the
 *    account we would have to give of our handling.
 *
 * It does not decide anything. What the material is, whether it is reported,
 * to whom, and when it may finally be deleted are the owner's calls, made from
 * the admin area with the playbook in front of them.
 *
 * @api Called by ModerateController (escalate) and the admin area (release).
 */
final class MediaEscalationService
{
    public const int REASON_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaEventLog $events,
        private readonly MediaDecisionService $decisions,
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

    /**
     * An admin has decided it was not what it looked like. The hold lifts and
     * normal moderation resumes; the photo does NOT go back on the map by
     * itself, because it was pending or approved before and that decision is
     * the desk's to make again.
     */
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
    public function held(): array
    {
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.escalatedAt IS NOT NULL
             ORDER BY m.escalatedAt DESC',
        )->getResult();

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

    /** Off the map at once — the same detach the takedown path uses, matched on the sm URL. */
    private function detachFromItem(MediaUpload $upload): void
    {
        $itemId = $upload->getItemId();
        if (null === $itemId) {
            return;
        }
        $item = $this->em->find(Item::class, $itemId);
        if (null === $item) {
            return;
        }

        $target = $this->decisions->describe($upload)['sm'];
        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }
        $kept = array_values(array_filter(
            $photos,
            static fn (mixed $photo): bool => !\is_array($photo) || ($photo['sm'] ?? null) !== $target,
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
        $itemId = $upload->getItemId();
        if (null === $itemId) {
            return '';
        }

        return $this->em->find(Item::class, $itemId)?->getName() ?? '';
    }
}
