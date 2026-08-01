<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "That photo is of me — take it down" (docs/specs/photo-uploads.md §6b).
 *
 * Account deletion already answers most of what GDPR Art. 17 asks of this
 * domain: an approved photo is a CC BY-SA contribution to the commons, the
 * personal data in it is the LINK to a person, and severing the link — which
 * MediaDisposalService::anonymizeFor() does — leaves nothing for erasure to
 * reach. The licence itself is irrevocable (CC BY-SA 4.0 §2(a)(1)) and neither
 * side can undo it.
 *
 * One case that reasoning does not cover: when the image DEPICTS the uploader.
 * Then the image is itself their personal data, a copyright licence does not
 * waive data-protection rights, and the objects have to actually go. This is
 * the path for that.
 *
 * Why a curator sees it at all, when the law does not leave much discretion:
 * two different requests arrive through one door. "This is a photo of me" must
 * be honoured. "I have changed my mind about contributing" must not be, or the
 * commons is only on loan and every approved photo is provisional. Nothing but
 * the rider's own words distinguishes them, so a human reads them. What the
 * curator is deciding is which request this is — not whether to feel like
 * granting it.
 *
 * Meanwhile the photo comes down IMMEDIATELY, before anyone looks. GDPR
 * Art. 18 makes restriction available while a request is verified, and if the
 * claim is true then leaving it up until somebody gets round to it is the one
 * outcome with a real cost.
 *
 * No new desk and no new verbs: requests surface in the existing moderation
 * queue and are granted or declined there, which is the same promise made for
 * per-photo approval (docs/specs/photo-uploads.md §5c).
 *
 * @api Called by MediaTakedownController and ModerateController.
 */
final class MediaTakedownService
{
    public const int REASON_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaEventLog $events,
        private readonly MediaDisposalService $disposal,
        private readonly MediaDecisionService $decisions,
        private readonly MessageService $messages,
    ) {
    }

    /**
     * The rider asks. Withholds the photo in the same transaction as the
     * request is recorded, so there is no window in which the request exists
     * and the photo is still on the map.
     *
     * @throws \InvalidArgumentException when the reason is empty or over-long
     */
    public function request(MediaUpload $upload, string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('media.takedown.error.reason_required');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new \InvalidArgumentException('media.takedown.error.reason_too_long');
        }

        $upload->requestTakedown($reason);
        $this->detach($upload);
        // The uploader is the actor here, not a curator: this is the one entry
        // in the log written by the person the photo is about.
        $this->events->append($upload->getId(), $upload->getUserId(), MediaAction::TakedownRequested, $reason);
        $this->em->flush();
    }

    /**
     * Granted: the objects go, the row and its history stay.
     *
     * deleteObjects() rather than purge(). What is being erased is the image,
     * and the row that survives holds no image — it records that a photo
     * existed, that its subject asked for it, and that we did as asked. Erasing
     * the evidence of an erasure request would leave us unable to show we
     * honoured it, which Art. 5(2) accountability is precisely about.
     */
    public function grant(MediaUpload $upload, User $curator, ?string $note = null): void
    {
        if (!$upload->isTakedownPending()) {
            return;
        }

        $this->events->append($upload->getId(), (int) $curator->getId(), MediaAction::TakedownGranted, $note);
        $this->disposal->deleteObjects($upload);
        $this->notify($upload, UserMessageKind::MediaTakedownGranted, 'messages.body.media_takedown_granted', $note);
        $this->em->flush();
    }

    /** Declined: the marker goes, the photo is published again, the reason stays on the row. */
    public function decline(MediaUpload $upload, User $curator, ?string $note = null): void
    {
        if (!$upload->isTakedownPending()) {
            return;
        }

        $upload->declineTakedown();
        $this->reattach($upload);
        $this->events->append($upload->getId(), (int) $curator->getId(), MediaAction::TakedownDeclined, $note);
        $this->notify($upload, UserMessageKind::MediaTakedownDeclined, 'messages.body.media_takedown_declined', $note);
        $this->em->flush();
    }

    /** @return list<MediaUpload> oldest first — a rights request waits for nobody's convenience */
    public function pending(): array
    {
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.takedownRequestedAt IS NOT NULL AND m.objectsDeletedAt IS NULL
             ORDER BY m.takedownRequestedAt ASC',
        )->getResult();

        return $rows;
    }

    /**
     * The desk's view of pending() — deliberately not region-scoped, unlike
     * the submission queue. A rights request is not editorial work to be
     * shared out by jurisdiction; whoever is on duty should see it.
     *
     * @return list<array{uuid: string, sm: string, reason: string, requestedAt: \DateTimeImmutable, itemName: string}>
     */
    public function pendingCards(): array
    {
        $cards = [];
        foreach ($this->pending() as $upload) {
            $requestedAt = $upload->getTakedownRequestedAt();
            if (null === $requestedAt) {
                continue;   // unreachable via pending(), and cheaper than a nullable in the view
            }
            $cards[] = [
                'uuid' => $upload->getId()->toRfc4122(),
                'sm' => (string) $this->decisions->describe($upload)['sm'],
                'reason' => $upload->getTakedownReason() ?? '',
                'requestedAt' => $requestedAt,
                'itemName' => $this->item($upload)?->getName() ?? '',
            ];
        }

        return $cards;
    }

    /** Drops the photo out of the item's gallery. Matched on the sm URL, which carries the uuid. */
    private function detach(MediaUpload $upload): void
    {
        $item = $this->item($upload);
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

        // An empty gallery drops the key rather than storing []: map.js reads
        // `f.photos || [f.photo]`, and an empty array is truthy, so leaving one
        // behind would shadow a legacy singular that may still be there.
        if ([] === $kept) {
            unset($attributes['photos']);
        } else {
            $attributes['photos'] = $kept;
        }
        $item->setAttributes($attributes);
    }

    /** Puts it back, in the shape approval originally gave it. */
    private function reattach(MediaUpload $upload): void
    {
        $item = $this->item($upload);
        if (null === $item) {
            return;
        }

        $entry = $this->decisions->describe($upload);
        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        $gallery = \is_array($photos) ? array_values(array_filter($photos, is_array(...))) : [];

        foreach ($gallery as $photo) {
            if (($photo['sm'] ?? null) === $entry['sm']) {
                return;   // already there — decline is idempotent
            }
        }

        $gallery[] = $entry;
        $attributes['photos'] = $gallery;
        $item->setAttributes($attributes);
    }

    private function item(MediaUpload $upload): ?Item
    {
        $itemId = $upload->getItemId();

        return null !== $itemId ? $this->em->find(Item::class, $itemId) : null;
    }

    /**
     * Tells the rider what happened. Silently does nothing for a photo whose
     * account is already gone: media_upload.user_id has no foreign key and is
     * nulled by anonymization, and there is nobody left to write to.
     */
    private function notify(MediaUpload $upload, UserMessageKind $kind, string $bodyKey, ?string $note): void
    {
        $userId = $upload->getUserId();
        if (null === $userId) {
            return;
        }

        $item = $this->item($upload);
        $this->messages->sendSystem(
            $userId,
            $kind,
            'media',
            $upload->getItemId() ?? 0,
            $item?->getName() ?? '',
            $bodyKey,
            [],
            $note,
        );
    }
}
